import type { ComputedRef, InjectionKey } from 'vue'
import type { InfolistRendererRegistries } from './types'

export const infolistRegistriesKey: InjectionKey<ComputedRef<InfolistRendererRegistries | undefined>> = Symbol('inlay-infolist-registries')
