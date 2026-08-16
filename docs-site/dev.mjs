import { createServer } from 'node:http'
import { readFile } from 'node:fs/promises'
import { existsSync, watch } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { buildSite } from './build.mjs'

const siteDir = path.dirname(fileURLToPath(import.meta.url))
const outputDir = path.join(siteDir, 'dist')
const port = Number(process.env.PORT ?? 4173)

await buildSite()

let rebuilding = false
let rebuildAgain = false
const rebuild = async () => {
  if (rebuilding) {
    rebuildAgain = true
    return
  }
  rebuilding = true
  try {
    await buildSite()
  } finally {
    rebuilding = false
    if (rebuildAgain) {
      rebuildAgain = false
      void rebuild()
    }
  }
}

for (const watched of [path.join(siteDir, '..', 'docs'), path.join(siteDir, '..', 'packages')]) {
  if (existsSync(watched)) watch(watched, { recursive: true }, () => void rebuild())
}

const contentTypes = {
  '.css': 'text/css; charset=utf-8',
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
}

const server = createServer(async (request, response) => {
  const requestPath = decodeURIComponent((request.url ?? '/').split('?')[0])
  const relative = requestPath === '/' ? 'index.html' : requestPath.replace(/^\//, '')
  const file = path.resolve(outputDir, relative)
  if (!file.startsWith(`${outputDir}${path.sep}`) && file !== outputDir) {
    response.writeHead(404).end('Not found')
    return
  }
  try {
    const data = await readFile(file)
    response.writeHead(200, { 'content-type': contentTypes[path.extname(file)] ?? 'application/octet-stream' })
    response.end(data)
  } catch {
    response.writeHead(404, { 'content-type': 'text/html; charset=utf-8' })
    response.end(await readFile(path.join(outputDir, '404.html')))
  }
})

server.listen(port, () => console.log(`Docs site running at http://localhost:${port}`))
