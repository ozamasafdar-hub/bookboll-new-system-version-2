// Isolated visual QA of the real Payments.vue against the real pure PHP report rules.
// Run with PAYMENT_TEST_PHP set to a PHP executable. Binds loopback only; no live API.
import { createServer } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath } from 'node:url'
import { spawn } from 'node:child_process'
import path from 'node:path'
const root = fileURLToPath(new URL('../../',import.meta.url))
const php = process.env.PAYMENT_TEST_PHP || 'php'
const mock = name => path.join(root,'tests/payment-preview',name)
const server = await createServer({ root, configFile:false, cacheDir:'node_modules/.vite-payment-preview', optimizeDeps:{entries:['tests/payment-preview/index.html']}, plugins:[vue(), {
  name:'payment-qa-fixtures',
  configureServer(server) {
    server.middlewares.use('/fixture-api', (req,res) => {
      const query = Object.fromEntries(new URL(req.url,'http://localhost').searchParams)
      const child = spawn(php,[path.resolve(root,'../../bookboll-BACKEND/tests/fixtures/payment-report.php')],{windowsHide:true})
      let body = '', error = ''
      child.stdout.on('data',data=>body += data); child.stderr.on('data',data=>error += data)
      child.on('error',e=>{res.statusCode=500;res.end(e.message)})
      child.on('close',code=>{ if(res.writableEnded)return;res.setHeader('Content-Type','application/json');res.statusCode=code?500:200;res.end(code?JSON.stringify({success:false,message:error}):body) })
      child.stdin.end(JSON.stringify(query))
    })
  },
}], resolve:{alias:[
  {find:/.*\/lib\/payments\.js$/,replacement:mock('mocks.js')},
  {find:/.*\/lib\/currentUser\.js$/,replacement:mock('mocks.js')},
  {find:/.*\/lib\/premium\.js$/,replacement:mock('mocks.js')},
  {find:/.*\/lib\/adminBrand\.js$/,replacement:mock('mocks.js')},
  {find:/.*\/components\/BookingDetailModal\.vue$/,replacement:mock('BookingStub.vue')},
]},server:{host:'127.0.0.1',port:5186,strictPort:true} })
await server.listen(); console.log('Isolated payment QA: http://127.0.0.1:5186/tests/payment-preview/index.html')
