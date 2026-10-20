<?php

// Qué se va a descargar. El token llega en el cuerpo JSON de un POST.

require_once __DIR__ . '/../bootstrap.php';

Api::run(['CompraProductoHandler', 'descarga']);
