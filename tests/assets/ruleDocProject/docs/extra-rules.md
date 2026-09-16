# Fixture project rule index, second catalogue

Exercises a declared `rulesDir`, so a bare class name in the class cell resolves
to a source path under the project rather than under this package.

Also two tables in one file with DIFFERENT summary columns, so the header cannot
be resolved once per file and reused for everything after it.

| Identifier             | Rule class  | Summary                        | Origin     |
| ---------------------- | ----------- | ------------------------------ | ---------- |
| `fixtureApp.extraRule` | `ExtraRule` | A rule from a second catalogue | Plan 00002 |

Prose between the tables, so the first table's header cannot leak into the
second by accident.

| Identifier            | Rule class | Origin     | Description                            |
| --------------------- | ---------- | ---------- | -------------------------------------- |
| `fixtureApp.lateRule` | `LateRule` | Plan 00003 | A rule whose summary column comes last |
