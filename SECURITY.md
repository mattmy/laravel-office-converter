# Security Policy

## Supported versions

Security fixes are provided for the latest released major version.

## Reporting

Please report vulnerabilities privately to the repository owner. Do not open a public issue before a fix is available.

Include the affected version, input format, minimal reproduction, expected impact, operating system, PHP version, and full LibreOffice version. Do not attach confidential source documents; use a minimal synthetic fixture.

LibreOffice processes untrusted native document formats. Production users must run the configured executable with a separate hardened profile per invocation, no trusted working directory, external-link updates disabled, Macro Security set to Very High, restricted operating-system privileges and resources, and process-tree termination on timeout.
