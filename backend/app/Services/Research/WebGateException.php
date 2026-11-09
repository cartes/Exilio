<?php

namespace App\Services\Research;

/** La puerta web rechazó una URL o una respuesta (política de seguridad, no un error del servidor remoto). */
class WebGateException extends \RuntimeException
{
}
