import '@testing-library/jest-dom/vitest'
import { cleanup, configure } from '@testing-library/react'
import { afterEach } from 'vitest'

// Margen para equipos cargados (compilando o con otros procesos): las esperas de findBy*/waitFor no deben fallar por lentitud.
configure({ asyncUtilTimeout: 4000 })

afterEach(() => cleanup())
