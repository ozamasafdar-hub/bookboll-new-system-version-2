import { createApp } from 'vue'
import Preview from './Preview.vue'
import '../../src/style.css'
import '../../src/dashboardTheme.css'
document.documentElement.classList.add('adm-shell')
createApp(Preview).mount('#app')
