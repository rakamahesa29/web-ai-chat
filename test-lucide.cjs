const jsdom = require('jsdom');
const { JSDOM } = jsdom;
const lucide = require('lucide');

const html = `
<details class="ds-thinking">
<summary>💭 DeepSeek Thinking...</summary><p>We need to respond in Bahasa Indonesia (default)...
I&#39;ll present it in a structured way with icons: <i data-lucide="search"></i> for search, <i data-lucide="terminal"></i> for terminal, <i data-lucide="alert-triangle"></i> for warnings. Dense and precise.</p>
</details><p><i data-lucide="search"></i> <strong>Investigasi mandiri dulu: kita culik log, baru eksekusi.</strong></p>
`;

const dom = new JSDOM(html);
const document = dom.window.document; global.document = document; global.window = dom.window;

// Simulate createIcons
lucide.createIcons({
  icons: lucide.icons,
  nameAttr: 'data-lucide',
  attrs: {
    class: 'lucide'
  },
  root: document.body
});

console.log(document.body.innerHTML);
