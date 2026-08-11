import type { AnyExtension, Editor } from '@tiptap/core'
import type { FormField } from './types'

export type RichEditorTool = {
  label: string
  compactLabel?: string
  isActive?: (editor: Editor, field: FormField) => boolean
  canRun?: (editor: Editor, field: FormField) => boolean
  run: (editor: Editor, field: FormField) => void
}

export type RichEditorPlugin = {
  name: string
  extensions?: AnyExtension[] | ((field: FormField) => AnyExtension[])
  tools?: Record<string, RichEditorTool>
}

export type RichEditorPluginRegistration = { unregister: () => boolean }

export class RichEditorPluginRegistry {
  private readonly plugins = new Map<string, { plugin: RichEditorPlugin; token: symbol }>()

  register(plugin: RichEditorPlugin): RichEditorPluginRegistration {
    assertPlugin(plugin, this.plugins.values())
    const token = Symbol(plugin.name)
    this.plugins.set(plugin.name, { plugin, token })
    return { unregister: () => this.unregister(plugin.name, token) }
  }

  all(): RichEditorPlugin[] { return [...this.plugins.values()].map(entry => entry.plugin) }

  extensions(field: FormField): AnyExtension[] {
    return this.all().flatMap(plugin => typeof plugin.extensions === 'function' ? plugin.extensions(field) : plugin.extensions ?? [])
  }

  tool(name: string): RichEditorTool | undefined {
    for (const plugin of this.all()) if (plugin.tools?.[name]) return plugin.tools[name]
    return undefined
  }

  private unregister(name: string, token: symbol): boolean {
    const current = this.plugins.get(name)
    if (!current || current.token !== token) return false
    return this.plugins.delete(name)
  }
}

export const richEditorPluginRegistry = new RichEditorPluginRegistry()

function assertPlugin(plugin: RichEditorPlugin, registered: Iterable<{ plugin: RichEditorPlugin }>): void {
  if (!/^[a-z][a-z0-9-]*$/.test(plugin.name)) throw new Error('Rich editor plugin names must be lowercase kebab-case identifiers.')
  const current = [...registered].map(entry => entry.plugin)
  if (current.some(entry => entry.name === plugin.name)) throw new Error(`Rich editor plugin [${plugin.name}] is already registered.`)
  const toolNames = Object.keys(plugin.tools ?? {})
  if (toolNames.some(name => !/^[A-Za-z][A-Za-z0-9_-]*$/.test(name))) throw new Error('Rich editor tool names must be safe identifiers.')
  if (toolNames.some(name => current.some(entry => name in (entry.tools ?? {})))) throw new Error('Rich editor plugin tool names must be unique.')
  for (const [name, tool] of Object.entries(plugin.tools ?? {})) {
    if (!tool.label.trim() || typeof tool.run !== 'function') throw new Error(`Rich editor tool [${name}] requires a label and run callback.`)
  }
}
