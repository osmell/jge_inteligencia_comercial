<?php

namespace App\Imports\Exceptions;

use RuntimeException;

/** El archivo no es un Excel válido o está dañado: es un error de datos, no un fallo técnico. */
class UnreadableFile extends RuntimeException {}
