import { describe, expect, it } from 'vitest'
import { withPageHost } from './lib/apiUrl'

describe('dirección con el host de la página', () => {
  it('reemplaza %host% por el nombre con el que se abrió la página', () => {
    expect(withPageHost('http://%host%:8000/api/v1', 'localhost')).toBe('http://localhost:8000/api/v1')
    expect(withPageHost('http://%host%:8000/api/v1', '192.168.0.187')).toBe('http://192.168.0.187:8000/api/v1')
  })

  it('deja intacta una dirección fija', () => {
    expect(withPageHost('https://api.jge.com/api/v1', 'localhost')).toBe('https://api.jge.com/api/v1')
  })
})
