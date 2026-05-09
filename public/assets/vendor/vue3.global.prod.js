# Vue 3 Vendor Note

The Vue 3 production build is loaded from CDN in `templates/pos.html.twig`:

```html
<script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
```

To vendor locally in the future, download the file and replace the CDN link with:

```html
<script src="/assets/vendor/vue3.global.prod.js"></script>
```

Current CDN version used: Vue 3 latest (unpkg.com/vue@3/dist/vue.global.prod.js)
