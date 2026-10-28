/**
 * `%host%` dentro de una dirección configurada es el nombre con el que se abrió la página: así la misma instalación sirve por
 * `localhost` y por la IP de la red local (la dirección del API y la de la página deben ser del mismo sitio para que viaje la cookie de sesión).
 */
export const PAGE_HOST = '%host%'

export const withPageHost = (url: string, hostname: string = window.location.hostname): string => url.replaceAll(PAGE_HOST, hostname)

/** Dirección del API: `VITE_API_URL` (puede llevar `%host%`) o, sin ella, el puerto 8000 del mismo host de la página. */
export const API_URL = withPageHost((import.meta.env.VITE_API_URL as string | undefined) ?? `http://${PAGE_HOST}:8000/api/v1`)
