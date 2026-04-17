# PHPStan Agent Repair Envelope

- Total issues: 2
- Clusters: 1
- Suppressed duplicates: 1

## [nullable-propagation] abc123def456
- Rule: `argument.type`
- Root cause: Nullable value reaches a non-null expectation.
- Repair strategy: Constrain nullability earlier or widen the target type to accept null.
- Suppressed duplicates: 1
