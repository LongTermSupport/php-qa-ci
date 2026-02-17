#!/usr/bin/env python3
"""
JUnit XML Log Parser

Parses PHPUnit JUnit XML logs to provide a clear summary of test failures and errors.

Usage:
    python3 qaConfig/scripts/parse-junit-logs.py [path/to/junit.xml]

    If no path provided, defaults to: var/qa/phpunit_logs/phpunit.junit.xml

Examples:
    # Parse default log location
    python3 qaConfig/scripts/parse-junit-logs.py

    # Parse specific log file
    python3 qaConfig/scripts/parse-junit-logs.py var/qa/phpunit_logs/phpunit.junit.xml

Output:
    - Summary of failures and errors
    - Breakdown by error type
    - First few unique error patterns for each type
    - Test names, file locations, and error messages
"""

import sys
import xml.etree.ElementTree as ET
from pathlib import Path
from typing import Dict, List, Optional
from datetime import datetime
import shutil
import re


def parse_phpunit_timeouts(phpunit_config_path: str = 'qaConfig/phpunit.xml') -> Dict[str, float]:
    """
    Parse PHPUnit configuration to extract timeout values.

    Args:
        phpunit_config_path: Path to phpunit.xml configuration file

    Returns:
        Dict with 'small', 'medium', 'large' timeout values in seconds

    Raises:
        FileNotFoundError: If config file doesn't exist
        ET.ParseError: If config file is invalid XML
        ValueError: If required timeout attributes are missing
    """
    tree = ET.parse(phpunit_config_path)
    root = tree.getroot()

    # Extract timeout attributes - these MUST exist in config
    try:
        timeouts = {
            'small': float(root.attrib['timeoutForSmallTests']),
            'medium': float(root.attrib['timeoutForMediumTests']),
            'large': float(root.attrib['timeoutForLargeTests'])
        }
    except KeyError as e:
        raise ValueError(f"PHPUnit config missing required timeout attribute: {e}") from e

    return timeouts


def parse_phpunit_stdout(stdout_path: str) -> Dict[str, any]:
    """
    Parse PHPUnit stdout log to get authoritative test counts and PHP warnings.

    PHPUnit stdout is the source of truth for risky/incomplete/skipped counts
    because XML format cannot distinguish between tests with DoesNotPerformAssertions
    and genuinely risky tests (both show assertions="0").

    Also parses PHP warnings/deprecations that appear in the stdout.

    Args:
        stdout_path: Path to PHPUnit stdout log file

    Returns:
        Dict with 'risky_count', 'incomplete_count', 'skipped_count', 'risky_tests', 'warnings', and 'warning_count'
    """
    try:
        with open(stdout_path, 'r') as f:
            content = f.read()

        result = {
            'risky_count': 0,
            'incomplete_count': 0,
            'skipped_count': 0,
            'risky_tests': [],
            'warnings': [],
            'warning_count': 0
        }

        # Parse summary line for counts
        # Example: "Tests: 2921, Assertions: 691449, Errors: 7, Failures: 23, Skipped: 10, Incomplete: 12, Risky: 5"
        incomplete_match = re.search(r'Incomplete:\s*(\d+)', content)
        if incomplete_match:
            result['incomplete_count'] = int(incomplete_match.group(1))

        skipped_match = re.search(r'Skipped:\s*(\d+)', content)
        if skipped_match:
            result['skipped_count'] = int(skipped_match.group(1))

        risky_match = re.search(r'Risky:\s*(\d+)', content)
        if risky_match:
            result['risky_count'] = int(risky_match.group(1))

            # Extract risky test details if present
            # Look for "There were X risky tests:" section
            risky_section = re.search(r'There were \d+ risky tests?:\s*\n\n(.*?)(?=\n\n(?:There were|ERRORS!|Generating))', content, re.DOTALL)
            if risky_section:
                risky_text = risky_section.group(1)
                # Parse individual risky test entries
                # Format: "1) FullTestName\nReason\n\n/path/file.php:123\n"
                test_pattern = re.findall(r'\d+\)\s+([^\n]+)\n([^\n]+)\n\n([^\n]+)', risky_text)
                for test_name, reason, file_path in test_pattern:
                    result['risky_tests'].append({
                        'test': test_name.strip(),
                        'reason': reason.strip(),
                        'file': file_path.strip()
                    })

        # Parse PHP warnings section
        # Format: "X test triggered Y PHP warning(s):" or "X tests triggered Y PHP warning(s):"
        warning_section_match = re.search(r'(\d+)\s+tests?\s+triggered\s+(\d+)\s+PHP\s+warnings?:', content)
        if warning_section_match:
            result['warning_count'] = int(warning_section_match.group(2))

            # Extract warning details
            # Look for section starting with "X test triggered Y PHP warning:"
            # Note: OK may have ANSI color codes like \x1b[30;43mOK\x1b[0m
            warning_section = re.search(
                r'\d+\s+tests?\s+triggered\s+\d+\s+PHP\s+warnings?:\s*\n\n(.*?)(?=\n\n(?:There were|\x1b\[|OK|ERRORS!|Tests:|\Z))',
                content,
                re.DOTALL
            )

            if warning_section:
                warnings_text = warning_section.group(1)
                # Parse individual warning entries
                # Format: "1) /path/to/file.php:123\nError message\n\nTriggered by:\n\n* TestClass::testMethod\n  /path/to/test.php:456"
                # Note: Message can be multi-line, so use non-greedy match up to "Triggered by:"
                warning_pattern = re.findall(
                    r'\d+\)\s+([^\n]+)\n(.*?)\n\nTriggered by:\s*\n\n\*\s+([^\n]+)\n\s+([^\n]+)',
                    warnings_text,
                    re.DOTALL
                )

                for source_file, message, test_name, test_file in warning_pattern:
                    result['warnings'].append({
                        'source_file': source_file.strip(),
                        'message': message.strip(),
                        'test': test_name.strip(),
                        'test_file': test_file.strip()
                    })

        return result

    except FileNotFoundError:
        return {
            'risky_count': -1,
            'incomplete_count': -1,
            'skipped_count': -1,
            'risky_tests': [],
            'warnings': [],
            'warning_count': 0,
            'error': f'Stdout log not found: {stdout_path}'
        }


def parse_junit_xml(xml_path: str, timeout_thresholds: Dict[str, float]) -> tuple[List[Dict], List[Dict], List[Dict], List[Dict]]:
    """
    Parse JUnit XML file and extract failures, errors, timeouts, and risky tests.

    Note: XML cannot distinguish between tests with DoesNotPerformAssertions
    and genuinely risky tests. Use parse_phpunit_stdout() for authoritative risky count.

    Args:
        xml_path: Path to JUnit XML file
        timeout_thresholds: Timeout values from PHPUnit config

    Returns:
        Tuple of (failures_list, errors_list, timeouts_list, risky_list)
    """
    tree = ET.parse(xml_path)
    root = tree.getroot()

    failures = []
    errors = []
    timeouts = []
    risky = []

    for testcase in root.iter('testcase'):
        test_name = testcase.get('name')
        test_class = testcase.get('class')
        test_file = testcase.get('file')
        test_time = float(testcase.get('time', 0))
        test_assertions = int(testcase.get('assertions', 0))

        for failure in testcase.findall('failure'):
            failures.append({
                'test': f"{test_class}::{test_name}",
                'file': test_file,
                'type': failure.get('type'),
                'message': failure.text[:500] if failure.text else ''
            })

        for error in testcase.findall('error'):
            error_type = error.get('type', '')
            error_message = error.text or ''

            errors.append({
                'test': f"{test_class}::{test_name}",
                'file': test_file,
                'type': error_type,
                'message': error_message[:500] if error_message else ''
            })

            # Detect timeout-related errors
            if 'time limit' in error_message.lower() or 'timeout' in error_message.lower():
                timeouts.append({
                    'test': f"{test_class}::{test_name}",
                    'file': test_file,
                    'time': test_time,
                    'error_type': error_type,
                    'message': error_message[:300]
                })

        # Check for risky tests (no assertions, not skipped, no errors/failures)
        # NOTE: This is imprecise - stdout log is authoritative for risky tests
        has_error = len(testcase.findall('error')) > 0
        has_failure = len(testcase.findall('failure')) > 0
        is_skipped = len(testcase.findall('skipped')) > 0
        has_no_assertions = test_assertions == 0 and not has_error and not has_failure and not is_skipped

        if has_no_assertions:
            # Check if approaching timeout (may indicate slow test)
            is_slow = test_time > timeout_thresholds['small'] * 0.8
            reasons = ["No assertions performed (may have DoesNotPerformAssertions - check stdout log)"]

            if is_slow:
                if test_time >= timeout_thresholds['medium']:
                    reasons.append(f"Slow: {test_time:.2f}s (needs #[Large])")
                elif test_time >= timeout_thresholds['small']:
                    reasons.append(f"Slow: {test_time:.2f}s (needs #[Medium] or #[Large])")
                else:
                    reasons.append(f"Slow: {test_time:.2f}s (approaching {timeout_thresholds['small']:.0f}s timeout)")

            risky.append({
                'test': f"{test_class}::{test_name}",
                'file': test_file,
                'time': test_time,
                'assertions': test_assertions,
                'reasons': reasons,
                'is_slow': is_slow
            })

    return failures, errors, timeouts, risky


def print_summary(failures: List[Dict], errors: List[Dict], timeouts: List[Dict], stdout_data: Dict) -> None:
    """Print summary of test results using authoritative stdout data."""
    print("SUMMARY")
    print("=" * 80)
    print(f"Total Failures: {len(failures)}")
    print(f"Total Errors: {len(errors)}")
    if timeouts:
        print(f"  (including {len(timeouts)} timeout errors)")

    # Use stdout as authoritative source for risky/incomplete/skipped
    if 'error' in stdout_data:
        print(f"\nWarning: Could not parse stdout log - {stdout_data['error']}")
        print("Risky/Incomplete/Skipped counts may be inaccurate (using XML)")
    else:
        print(f"Total Risky: {stdout_data['risky_count']}")
        print(f"Total Incomplete: {stdout_data['incomplete_count']}")
        print(f"Total Skipped: {stdout_data['skipped_count']}")
    print()


def print_error_breakdown(errors: List[Dict]) -> None:
    """Print detailed breakdown of errors by type."""
    if not errors:
        return

    # Group errors by type
    error_types: Dict[str, List[Dict]] = {}
    for error in errors:
        error_type = error['type']
        if error_type not in error_types:
            error_types[error_type] = []
        error_types[error_type].append(error)

    print("ERROR TYPES BREAKDOWN")
    print("=" * 80)

    for error_type, error_list in error_types.items():
        print(f"\n{error_type}: {len(error_list)} occurrences")
        print("-" * 80)

        # Show ALL errors (not just unique messages)
        for error in error_list:
            # Extract key part of error message
            msg_lines = error['message'].split('\n')
            if len(msg_lines) > 1:
                key_msg = msg_lines[1]  # Usually the actual error
            else:
                key_msg = error['message'][:200]

            print(f"  Test: {error['test']}")
            print(f"  File: {error['file']}")
            print(f"  Error: {key_msg}")
            print()


def print_failure_breakdown(failures: List[Dict]) -> None:
    """Print detailed breakdown of failures."""
    if not failures:
        return

    print("\nFAILURES BREAKDOWN")
    print("=" * 80)

    for failure in failures:
        print(f"\nTest: {failure['test']}")
        print(f"File: {failure['file']}")
        print(f"Type: {failure['type']}")
        print(f"Message: {failure['message']}")
        print("-" * 80)


def print_timeout_breakdown(timeouts: List[Dict]) -> None:
    """Print detailed breakdown of timeout errors."""
    if not timeouts:
        return

    print("\nTIMEOUT ERRORS BREAKDOWN")
    print("=" * 80)
    print(f"Found {len(timeouts)} timeout-related errors")
    print()

    for timeout in timeouts:
        print(f"Test: {timeout['test']}")
        print(f"File: {timeout['file']}")
        print(f"Time: {timeout['time']:.2f}s")
        print(f"Error Type: {timeout['error_type']}")
        print(f"Message: {timeout['message']}")
        print("-" * 80)


def print_risky_breakdown_from_stdout(stdout_data: Dict) -> None:
    """Print detailed breakdown of risky tests from stdout log (authoritative source)."""
    risky_count = stdout_data['risky_count']
    risky_tests = stdout_data['risky_tests']

    if risky_count == 0:
        return

    print("\nRISKY TESTS BREAKDOWN (from PHPUnit stdout)")
    print("=" * 80)
    print(f"Found {risky_count} risky tests")
    print()

    if risky_tests:
        for risky_test in risky_tests:
            print(f"Test: {risky_test['test']}")
            print(f"Reason: {risky_test['reason']}")
            print(f"File: {risky_test['file']}")
            print("-" * 80)
    else:
        print("(Details not available in stdout log)")


def print_warnings_breakdown(stdout_data: Dict) -> None:
    """Print detailed breakdown of PHP warnings from stdout log."""
    warning_count = stdout_data['warning_count']
    warnings = stdout_data['warnings']

    if warning_count == 0:
        return

    print("\nPHP WARNINGS")
    print("=" * 80)
    print(f"Found {warning_count} PHP warning(s)")
    print()

    if warnings:
        for warning in warnings:
            print(f"Source: {warning['source_file']}")
            print(f"Message: {warning['message']}")
            print(f"Triggered by: {warning['test']}")
            print(f"Test file: {warning['test_file']}")
            print("-" * 80)
    else:
        print("(Warning details not available in stdout log)")


def print_risky_breakdown(risky: List[Dict]) -> None:
    """Print detailed breakdown of risky tests from XML (may include false positives)."""
    if not risky:
        return

    print("\nRISKY TESTS BREAKDOWN (from XML - may include false positives)")
    print("=" * 80)
    print("Note: XML cannot distinguish between tests with #[DoesNotPerformAssertions]")
    print("and genuinely risky tests. Use stdout log for authoritative count.")
    print()

    for test in risky:
        print(f"Test: {test['test']}")
        print(f"File: {test['file']}")
        print(f"Time: {test['time']:.2f}s")
        print(f"Assertions: {test['assertions']}")
        print(f"Reasons:")
        for reason in test['reasons']:
            print(f"  - {reason}")
        print("-" * 80)


def archive_non_timestamped_log(log_dir: Path) -> Optional[Path]:
    """
    Archive the non-timestamped phpunit.junit.xml if it exists and is newer.

    Returns:
        Path to the newly archived file if archived, None otherwise
    """
    non_timestamped = log_dir / 'phpunit.junit.xml'

    if not non_timestamped.exists():
        return None

    # Get the most recent timestamped file by modification time
    timestamped_files = sorted(log_dir.glob('phpunit.junit.*.xml'), key=lambda p: p.stat().st_mtime, reverse=True)

    # If no timestamped files exist, or non-timestamped is newer, archive it
    should_archive = True
    if timestamped_files:
        most_recent = timestamped_files[0]
        # Compare modification times
        if non_timestamped.stat().st_mtime <= most_recent.stat().st_mtime:
            should_archive = False

    if should_archive:
        # Create timestamped filename using file's modification time
        mtime = datetime.fromtimestamp(non_timestamped.stat().st_mtime)
        timestamp = mtime.strftime('%Y%m%d-%H%M%S')
        archived_name = f'phpunit.junit.{timestamp}.xml'
        archived_path = log_dir / archived_name

        # Copy the file to timestamped version
        shutil.copy2(non_timestamped, archived_path)
        print(f"Archived non-timestamped log: {archived_name}")
        print()

        return archived_path

    return None


def main():
    """Main entry point."""
    log_dir = Path('var/qa/phpunit_logs')

    # Check if log directory exists
    if not log_dir.exists():
        print(f"Error: PHPUnit log directory not found: {log_dir}", file=sys.stderr)
        print(f"\nNo test runs found. Run tests first with: ./bin/qa -t unit", file=sys.stderr)
        sys.exit(1)

    # Determine XML file path
    if len(sys.argv) > 1:
        provided_path = Path(sys.argv[1])

        # If user explicitly passed the non-timestamped file, archive it first
        if provided_path.name == 'phpunit.junit.xml' and provided_path.exists():
            print(f"Non-timestamped log explicitly requested, archiving first...")
            archived_path = archive_non_timestamped_log(log_dir)
            if archived_path:
                xml_path = str(archived_path)
                print(f"Parsing archived log: {archived_path.name}")
                print()
            else:
                # Already archived, find the most recent by modification time
                log_files = sorted(log_dir.glob('phpunit.junit.*.xml'), key=lambda p: p.stat().st_mtime, reverse=True)
                if log_files:
                    xml_path = str(log_files[0])
                    print(f"Using most recent archived log: {log_files[0].name}")
                    print()
                else:
                    print(f"Error: No archived logs found", file=sys.stderr)
                    sys.exit(1)
        else:
            xml_path = str(provided_path)
    else:
        # No specific file provided - check for non-timestamped file first
        archived_path = archive_non_timestamped_log(log_dir)

        if archived_path:
            # Use the newly archived file
            xml_path = str(archived_path)
            print(f"Parsing newly archived log: {archived_path.name}")
            print()
        else:
            # Find most recent timestamped log file by modification time
            log_files = sorted(log_dir.glob('phpunit.junit.*.xml'), key=lambda p: p.stat().st_mtime, reverse=True)

            if not log_files:
                print(f"Error: No PHPUnit log files found in {log_dir}", file=sys.stderr)
                print(f"\nNo test runs found. Run tests first with: ./bin/qa -t unit", file=sys.stderr)
                sys.exit(1)

            # Use the most recent (first after reverse sort)
            xml_path = str(log_files[0])
            print(f"Parsing most recent test run: {log_files[0].name}")
            print()

    xml_file = Path(xml_path)

    if not xml_file.exists():
        print(f"Error: JUnit XML file not found: {xml_path}", file=sys.stderr)
        print(f"\nUsage: {sys.argv[0]} [path/to/junit.xml]", file=sys.stderr)
        print(f"Default: Automatically finds most recent timestamped log", file=sys.stderr)
        sys.exit(1)

    try:
        # Parse PHPUnit config for timeout thresholds
        timeout_thresholds = parse_phpunit_timeouts()

        # Derive stdout log path from XML path
        # XML: phpunit.junit.20251112-080549.xml -> stdout: phpunit.20251112-080549.log
        stdout_path = xml_path.replace('.junit.', '.').replace('.xml', '.log')

        # Parse both XML and stdout logs
        stdout_data = parse_phpunit_stdout(stdout_path)
        failures, errors, timeouts, xml_risky = parse_junit_xml(xml_path, timeout_thresholds)

        # Print summaries using authoritative stdout data
        print_summary(failures, errors, timeouts, stdout_data)
        print_error_breakdown(errors)
        print_failure_breakdown(failures)

        # Print timeouts if any detected
        if timeouts:
            print_timeout_breakdown(timeouts)

        # Print risky tests from stdout if any (authoritative source)
        if stdout_data['risky_count'] > 0:
            print_risky_breakdown_from_stdout(stdout_data)
        elif xml_risky:
            # If stdout shows 0 risky but XML found some, note the discrepancy
            print("\nNOTE: XML shows tests with 0 assertions, but PHPUnit stdout reports 0 risky tests.")
            print("These tests likely have #[DoesNotPerformAssertions] attribute.")
            print(f"XML found {len(xml_risky)} tests with 0 assertions (not shown as risky)")

        # Print PHP warnings if any detected
        if stdout_data['warning_count'] > 0:
            print_warnings_breakdown(stdout_data)

        # Exit with error code if there were failures, errors, or risky tests
        # Risky tests indicate problems (no assertions, useless tests) and should fail the build
        if failures or errors or stdout_data['risky_count'] > 0:
            sys.exit(1)
        else:
            print("\n✓ All tests passed!")
            if stdout_data['incomplete_count'] > 0 or stdout_data['skipped_count'] > 0:
                print(f"  ({stdout_data['incomplete_count']} incomplete, {stdout_data['skipped_count']} skipped)")
            sys.exit(0)

    except ET.ParseError as e:
        print(f"Error parsing XML file: {e}", file=sys.stderr)
        sys.exit(2)
    except Exception as e:
        print(f"Unexpected error: {e}", file=sys.stderr)
        sys.exit(2)


if __name__ == '__main__':
    main()
