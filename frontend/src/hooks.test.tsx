import { act, renderHook, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from './lib/api'
import { useDebounced } from './lib/useDebounced'
import { useExport } from './lib/useExport'
import { useFilters } from './lib/useFilters'
import { useFitRows } from './lib/useFitRows'
import { useFormValues } from './lib/useFormValues'
import { useSubmit } from './lib/useSubmit'

afterEach(() => vi.useRealTimers())

describe('useFilters', () => {
  it('cambiar un filtro vuelve a la primera página; cambiar de página no toca los filtros', () => {
    const { result } = renderHook(() => useFilters({ text: '', status: 'open' }))
    act(() => result.current.setPage(4))
    expect(result.current.page).toBe(4)

    act(() => result.current.set({ status: 'closed' }))
    expect(result.current.filters).toEqual({ text: '', status: 'closed' })
    expect(result.current.page).toBe(1)

    act(() => result.current.setPage(2))
    expect(result.current.filters.status).toBe('closed')
  })
})

describe('useFormValues', () => {
  it('cambia un campo sin tocar los demás y permite derivar el valor actual', () => {
    const { result } = renderHook(() => useFormValues({ name: 'Ana', ids: [1] as number[] }))
    act(() => result.current.set('name', 'Luis'))
    act(() => result.current.update('ids', (prev) => [...prev, 2]))
    expect(result.current.values).toEqual({ name: 'Luis', ids: [1, 2] })
  })
})

describe('useSubmit', () => {
  it('devuelve true y limpia los errores cuando la acción termina bien', async () => {
    const { result } = renderHook(() => useSubmit())
    let ok = false
    await act(async () => { ok = await result.current.run(async () => undefined) })
    expect(ok).toBe(true)
    expect(result.current.errors).toEqual({})
    expect(result.current.saving).toBe(false)
  })

  it('muestra los errores por campo de un 422', async () => {
    const { result } = renderHook(() => useSubmit())
    let ok = true
    await act(async () => { ok = await result.current.run(async () => { throw new ApiError(422, 'Datos no válidos', { name: ['Obligatorio'] }) }) })
    expect(ok).toBe(false)
    expect(result.current.errors).toEqual({ name: ['Obligatorio'] })
  })

  it('un error inesperado va al campo de respaldo', async () => {
    const { result } = renderHook(() => useSubmit())
    await act(async () => { await result.current.run(async () => { throw new Error('Sin conexión') }, 'code') })
    expect(result.current.errors).toEqual({ code: ['Sin conexión'] })
  })
})

describe('useExport', () => {
  it('marca el formato mientras se genera y guarda el error si falla', async () => {
    const { result } = renderHook(() => useExport<'csv' | 'pdf'>())
    let release: () => void = () => undefined
    const pending = new Promise<void>((resolve) => { release = resolve })

    let first: Promise<void> = Promise.resolve()
    act(() => { first = result.current.run('csv', () => pending) })
    expect(result.current.exporting).toBe('csv')
    await act(async () => { release(); await first })
    expect(result.current.exporting).toBeNull()

    await act(async () => { await result.current.run('pdf', async () => { throw new Error('Sin permiso') }) })
    expect(result.current.error).toBe('Sin permiso')
  })
})

describe('useDebounced', () => {
  it('entrega el valor hasta que deja de cambiar', () => {
    vi.useFakeTimers()
    const { result, rerender } = renderHook(({ v }) => useDebounced(v, 300), { initialProps: { v: 'a' } })
    rerender({ v: 'ab' })
    rerender({ v: 'abc' })
    expect(result.current).toBe('a')
    act(() => { vi.advanceTimersByTime(299) })
    expect(result.current).toBe('a')
    act(() => { vi.advanceTimersByTime(1) })
    expect(result.current).toBe('abc')
  })
})

describe('useFitRows', () => {
  it('sigue la altura de la ventana y avisa solo cuando la cantidad cambia', async () => {
    const original = window.innerHeight
    Object.defineProperty(window, 'innerHeight', { configurable: true, writable: true, value: 800 })
    const onChange = vi.fn()
    const rowsFor = (h: number) => Math.floor(h / 100)
    const { result } = renderHook(() => useFitRows(rowsFor, onChange))
    expect(result.current).toBe(8)

    window.innerHeight = 850   // misma cantidad de filas: no avisa
    window.dispatchEvent(new Event('resize'))
    await new Promise((r) => setTimeout(r, 250))
    expect(onChange).not.toHaveBeenCalled()

    window.innerHeight = 1000
    window.dispatchEvent(new Event('resize'))
    await waitFor(() => expect(result.current).toBe(10))
    expect(onChange).toHaveBeenCalledTimes(1)
    window.innerHeight = original
  })
})
