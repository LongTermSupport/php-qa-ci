# `phpqaci.cronMissingInterval` — a cron command states its schedule

**Rule**: `RequireCronIntervalInDescriptionRule`
**Bundle**: [`rules-optional-symfony.neon`](../../rules-optional-symfony.neon) (opt in, Symfony)

## What fires

An `app:cron:*` console command whose description is missing, or does not end with a schedule
interval in square brackets.

```php
#[AsCommand(name: 'app:cron:prune-tokens', description: 'Clean up expired tokens.')]
```

Accepted suffixes: `[every Nm]`, `[every Nh]`, `[every Nd]`.

## Why this is a hazard

A cron command and the thing that runs it live in different places — the command in `src/`, the
schedule in a systemd timer, a Kubernetes CronJob or a crontab, often in a different repository
owned by a different person.

Nothing connects them. A command can be written, merged and deployed while never being
scheduled at all, and the failure is silent: no error, no log, simply a job that never runs.
The mirror case is worse — a schedule that outlives its command, or one whose interval was
chosen once from a conversation nobody wrote down. When the token-pruning job runs hourly and
the code assumed every fifteen minutes, the symptom is a slow leak somewhere else entirely.

Putting the interval in the description makes `php bin/console list app:cron` a complete
statement of what the schedule should be. The operator writing the timer reads it from the
application rather than from a ticket, and the next person can diff intent against reality.

## The correct construction

**End the description with the interval**:

```php
#[AsCommand(
    name: 'app:cron:prune-tokens',
    description: 'Clean up expired tokens. [every 1h]',
)]
final class PruneTokensCommand extends Command { ... }
```

```
[every 15m]    [every 1h]    [every 6h]    [every 1d]
```

Two things follow that are worth doing at the same time:

**Make the command idempotent and safe to run at any interval.** The description is intent, not
enforcement — nothing stops an operator scheduling it differently, so the command should not
break if they do.

**Do not encode the schedule anywhere else in the code.** If the command also checks the clock
to decide whether it should run, there are now two schedules that can disagree. Let the
scheduler schedule.

## What is deliberately not flagged

Only `app:cron:*` commands are checked. An ordinary console command is invoked by a person or a
deploy step and has no schedule to state.

The rule checks the description's *shape*, not whether the interval is correct or whether a
timer exists — neither is visible from the source. It is a prompt to state the intent, and the
value comes from the statement being somewhere an operator will actually look.

## If you believe an instance is legitimate

A cron-namespaced command with no meaningful interval is usually not a cron command: if it is
triggered by an event, a queue or an operator, move it out of the `app:cron:` namespace and the
rule stops applying — which is the more accurate fix, because the name was telling readers
something untrue.
