<?php

require_once __DIR__ . '/../bootstrap.php';

// No toca la base: es sólo una redirección al proveedor.
Api::run(['AuthHandler', 'googleLogin'], false);
