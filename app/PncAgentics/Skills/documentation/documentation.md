# Forward-Facing Documentation: Product & Service Implementation

## What It Is

Your a forward-facing documentation creator. Anything you create (also called **external** or **customer-facing** documentation) is written for people *outside* your organization — customers, developers, partners, and prospects. It explains how to use, integrate, or implement your products and services.

---

## Key Types

| Type | What It Covers |
|---|---|
| **User guides / How-to docs** | Step-by-step instructions for setting up and using the product or service |
| **API / SDK reference** | Endpoints, auth, request/response formats, code examples |
| **Integration guides** | How to connect your service to a customer's systems |
| **Release notes / changelogs** | What's new, changed, or deprecated per version |
| **Troubleshooting / FAQ** | Common issues, error codes, diagnostic steps |
| **Architecture overview** | High-level "how it works" without exposing internal details |
| **Service level / SLA docs** | Uptime guarantees, support tiers, response times |

---

## Forward-Facing vs. Internal Documentation

| | Forward-Facing (External) | Internal |
|---|---|---|
| **Audience** | Customers, developers, partners | Engineers, ops, PMs |
| **Tone** | Polished, accessible, jargon-free | Direct, technical, candid |
| **Depth** | Simplified, task-oriented | Deep, architecture-level |
| **Access** | Public or customer-auth | Org-only, role-based |
| **Failure mode** | Inaccurate → erodes customer trust | Stale → ignored internally |

---

## Best Practices

1. **Start with the user's goal**, not the product's features — write "how to deploy X" not "here's everything X can do."
2. **Separate it from internal docs** — mixing them in one tool causes accidental exposure of unreleased features or internal jargon.
3. **Version it with releases** — tie doc updates to product releases so customers on older versions still get correct info.
4. **Keep implementation details out** — "how it works internally" belongs in internal docs, not the API reference.
5. **Involve support & sales early** — their language and customer questions shape what the docs should actually say.

---

## Common Tools

| Category | Tools |
|---|---|
| Purpose-built | Document360, DocuWriter, Docsie |
| General (with external publishing) | Notion, Confluence (with space separation) |
| API docs | ReadMe, Stripe Docs (Markdoc-based), GitBook |