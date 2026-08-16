<?php

/**
 * Credenciales de cobro de cada página.
 *
 * El access token de Mercado Pago permite cobrar en nombre del dueño, así que
 * se guarda cifrado y nunca vuelve al frontend: al editor sólo se le dice qué
 * credencial hay cargada, mostrando los últimos cuatro caracteres.
 */
class Cobros
{
    /**
     * Lo que se le puede mostrar al dueño en el editor: si hay credencial,
     * en qué modo está y cuál es, sin devolver el secreto.
     */
    public static function estado($db, $pageId)
    {
        $stmt = $db->prepare('SELECT * FROM page_payment_settings WHERE page_id = ?');
        $stmt->execute([(int) $pageId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($fila === false) {
            return ['configurado' => false, 'modo' => null, 'token_ultimos4' => null,
                    'public_key' => null, 'verificado_en' => null];
        }

        return [
            'configurado'    => true,
            'modo'           => $fila['modo'],
            'token_ultimos4' => $fila['token_ultimos4'],
            // La public key no es secreta: es la que usa el navegador.
            'public_key'     => $fila['public_key'],
            'verificado_en'  => $fila['verificado_en'],
        ];
    }

    /** true si la página puede cobrar de verdad. */
    public static function estaConfigurado($db, $pageId)
    {
        $stmt = $db->prepare('SELECT 1 FROM page_payment_settings WHERE page_id = ?');
        $stmt->execute([(int) $pageId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Access token descifrado de una página, o null si no hay o está corrupto.
     *
     * Sólo lo usa el servidor para hablar con Mercado Pago. Nunca se devuelve
     * en una respuesta HTTP.
     */
    public static function tokenDe($db, $pageId)
    {
        $stmt = $db->prepare('SELECT access_token_cifrado FROM page_payment_settings WHERE page_id = ?');
        $stmt->execute([(int) $pageId]);
        $cifrado = $stmt->fetchColumn();

        if ($cifrado === false) {
            return null;
        }

        return Cripto::descifrar($cifrado);
    }

    /** Access token de la página dueña de un evento. */
    public static function tokenDelEvento($db, $linkId)
    {
        $stmt = $db->prepare('
            SELECT lg.page_id
            FROM links l
            INNER JOIN link_groups lg ON lg.id = l.group_id
            WHERE l.id = ?
        ');
        $stmt->execute([(int) $linkId]);
        $pageId = $stmt->fetchColumn();

        return $pageId === false ? null : self::tokenDe($db, $pageId);
    }

    /**
     * Guarda las credenciales, verificándolas antes contra Mercado Pago.
     *
     * Se verifica primero a propósito: guardar una credencial que no funciona
     * deja al dueño creyendo que puede cobrar, y el error recién aparece
     * cuando un comprador real intenta pagar.
     *
     * @return array{ok: bool, error: string|null, cuenta: string|null}
     */
    public static function guardar($db, $pageId, $token, $publicKey, $http = null)
    {
        if (!Cripto::disponible()) {
            return ['ok' => false, 'cuenta' => null,
                    'error' => 'El servidor no está configurado para guardar credenciales de cobro'];
        }

        $token = trim((string) $token);
        $publicKey = trim((string) $publicKey);

        if (!MercadoPago::pareceToken($token)) {
            return ['ok' => false, 'cuenta' => null,
                    'error' => 'El access token no tiene el formato de Mercado Pago (APP_USR-… o TEST-…)'];
        }

        if (!MercadoPago::pareceClavePublica($publicKey)) {
            return ['ok' => false, 'cuenta' => null,
                    'error' => 'La public key no tiene el formato de Mercado Pago (APP_USR-… o TEST-…)'];
        }

        $modoToken = MercadoPago::modoDelToken($token);

        // Mezclar credenciales de prueba y de producción es un error silencioso:
        // el checkout abre pero el cobro nunca llega a la cuenta real.
        if ($modoToken !== MercadoPago::modoDelToken($publicKey)) {
            return ['ok' => false, 'cuenta' => null,
                    'error' => 'Una credencial es de prueba y la otra de producción: tienen que ser del mismo par'];
        }

        $verificacion = (new MercadoPago($token, $http))->verificar();

        if (!$verificacion['ok']) {
            return ['ok' => false, 'cuenta' => null, 'error' => $verificacion['error']];
        }

        $stmt = $db->prepare('
            INSERT INTO page_payment_settings
                (page_id, access_token_cifrado, token_ultimos4, public_key, modo, verificado_en)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                access_token_cifrado = VALUES(access_token_cifrado),
                token_ultimos4 = VALUES(token_ultimos4),
                public_key = VALUES(public_key),
                modo = VALUES(modo),
                verificado_en = NOW()
        ');
        $stmt->execute([
            (int) $pageId,
            Cripto::cifrar($token),
            Cripto::ultimos4($token),
            $publicKey,
            $modoToken,
        ]);

        return ['ok' => true, 'error' => null, 'cuenta' => $verificacion['cuenta']];
    }

    public static function borrar($db, $pageId)
    {
        $stmt = $db->prepare('DELETE FROM page_payment_settings WHERE page_id = ?');
        $stmt->execute([(int) $pageId]);
    }
}
