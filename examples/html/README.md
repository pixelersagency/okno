# Plain HTML

For any server-rendered site: PHP templates, Rails, Django, Laravel, Eleventy, Hugo…

1. Copy `okno-bridge.js` (from **Okno → Démarrer**, or `bridge/dist/`) to your public folder.
2. Paste the loader from `index.html` into your `<head>`.
3. Print the WordPress post ID on a container and the field name on each editable element.
4. Send the `frame-ancestors` header, for example in nginx:

```nginx
add_header Content-Security-Policy "frame-ancestors 'self' https://wp.example.com" always;
```

or in Apache:

```apache
Header always set Content-Security-Policy "frame-ancestors 'self' https://wp.example.com"
```

If your pages also send `X-Frame-Options: DENY` or `SAMEORIGIN`, remove it: it blocks the editor regardless of the CSP header. **Okno → Démarrer → Vérifier les en-têtes** tells you which header is in the way.
