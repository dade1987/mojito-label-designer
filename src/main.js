import { createApp } from 'vue'
// Font del canvas: metriche quasi identiche al CG Triumvirate Bold Condensed
// della stampante (vedi calibrazione in utils/canvasDisplay.js).
import '@fontsource/roboto-condensed/700.css'
import './style.css'
import App from './App.vue'

const app = createApp(App)

// L'anteprima è calibrata su Roboto Condensed Bold. Se il canvas viene
// disegnato PRIMA che il webfont sia caricato, il browser usa il fallback
// (Arial, ~20% più largo): l'etichetta appare "allargata" finché non avviene
// lo swap (font-display: swap di @fontsource). Aspettiamo quindi il font prima
// del primo render, così la larghezza è corretta su ogni schermo/risoluzione.
// Timeout di sicurezza per non lasciare mai la pagina vuota se il font tarda.
let mounted = false
const mount = () => {
  if (mounted) return
  mounted = true
  app.mount('#app')
}

const fonts = typeof document !== 'undefined' ? document.fonts : null

if (fonts?.load) {
  Promise.race([
    fonts.load("700 1em 'Roboto Condensed'", 'ABCabcgé0123'),
    new Promise((resolve) => setTimeout(resolve, 1500)),
  ]).then(mount, mount)
} else {
  mount()
}
