# Template Impact Guide

Hyper fields and native Craft Link fields are not API-identical.

## Common Hyper patterns to review

Search for:

```text
.url
.text
.target
getLink(
linkValue
.type
getElement(
hasElement(
Hyper
```

## Typical changes

Hyper often appears as:

```twig
{{ entry.cta.url }}
{{ entry.cta.text }}
{{ entry.cta.target }}
{{ entry.cta.getLink()|raw }}
{% for link in entry.links %}
  {{ link.url }}
{% endfor %}
```

Craft Link fields return a `craft\fields\data\LinkData` object.

Typical native usage:

```twig
{% set link = entry.cta %}
{% if link %}
  <a href="{{ link.url }}"{{ attr(link.attributes) }}>{{ link.label }}</a>
{% endif %}
```

Review anything that depends on:

- `getLink()`
- Hyper link type class checks
- `getElement()` / `hasElement()`
- custom fields attached to a Hyper link type
- embed HTML or provider data
- loops over multi-link Hyper fields
