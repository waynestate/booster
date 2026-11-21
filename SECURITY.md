# Security policy

## Report a vulnerability

Please do not open a public issue, pull request or discussion.

- **Preferred:** use GitHub's private vulnerability reporting on this repository (the "Report a vulnerability"
  button under the Security tab).
- **Or email** ck7939@wayne.edu.

Tell us the version, your configuration and the steps that show the problem. We reply within 5 business days, agree
a disclosure date with you, and aim to ship a fix within 30 days for anything serious.

## Supported versions

Security fixes go to the latest minor release (before 1.0) or the latest minor of the current major.

## How fixes are announced

The fixed version's CHANGELOG entry starts with a `Security:` line: who is affected, what could happen, the affected
versions and what to do. A GitHub security advisory follows, so `composer audit` warns apps on an affected version.
