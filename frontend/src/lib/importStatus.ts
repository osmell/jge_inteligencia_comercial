import type { ImportStatus } from './types'

/** Estados en los que el lote aún se está procesando en segundo plano. */
export const IN_PROGRESS: ImportStatus[] = ['pending', 'validating', 'importing']
