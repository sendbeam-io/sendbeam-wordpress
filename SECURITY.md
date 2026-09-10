# Security

## Reporting a vulnerability

Email **hello@sendbeam.io** with "security" in the subject. Please do not open a public issue for anything
exploitable.

Tell us what you found, how to reproduce it, and what an attacker could do with it. We will confirm receipt
within two business days and tell you what we intend to do about it. If you would like credit, say so and we
will name you when the fix ships; if you would rather stay anonymous, that is fine too.

We do not run a paid bug bounty.

## What is in scope here

This repository is the WordPress plugin. In scope:

- Anything in this plugin that lets someone act as another user, read another site's API key, or run code
  or markup they should not — cross-site scripting, missing capability or nonce checks, an unsafe option
  value reaching output.
- The plugin leaking the site's SendBeam API key anywhere it should not appear.
- The message channel between an embedded form and the page it sits in.

Out of scope here, but very much in scope for us — report them the same way:

- **sendbeam.io itself**, including the hosted form pages, the API and the application. See
  <https://sendbeam.io/.well-known/security.txt>.
- Anything requiring an administrator to act against their own site. A user who can already install plugins
  or edit theme files can do anything; that is WordPress's model, not a flaw in this one.
- Findings from an automated scanner with no demonstrated impact.

## Supported versions

The latest release. This plugin is small and there are no long-term support branches; if something needs
fixing, it is fixed in a new release rather than backported.
