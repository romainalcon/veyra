---
name: create-feature
description: Implement one Veyra feature according to its specs, architecture, and tests.
---
# Create feature
1. Read `CLAUDE.md` and relevant `docs/`.
2. Check the feature is in the agreed release scope.
3. Inspect existing code and propose a short plan.
4. Implement backend and frontend as one minimal vertical slice.
5. Add validation, authorization, and automated tests.
6. Run checks; report actual results and outstanding issues.
Do not bypass tenant isolation or SSRF defenses.
