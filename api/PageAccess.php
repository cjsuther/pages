<?php

// Helpers de autorización para administración de páginas.
// "Gestionar" = ser dueño (pages.user_id) O administrador aceptado (page_admins.status = 'accepted')
// O administrar la plataforma (ver Plataforma).
// Acciones reservadas al dueño (borrar página, gestionar admins) deben usar isOwner(),
// que a propósito NO contempla a la plataforma: nadie pierde el control de lo suyo.

class PageAccess
{
    public static function canManage($db, $pageId, $userId)
    {
        if (Plataforma::esAdmin($db, $userId)) {
            return self::existePagina($db, $pageId);
        }

        $stmt = $db->prepare('
            SELECT 1 FROM pages p
            WHERE p.id = ? AND (
                p.user_id = ?
                OR EXISTS (
                    SELECT 1 FROM page_admins pa
                    WHERE pa.page_id = p.id AND pa.user_id = ? AND pa.status = "accepted"
                )
            ) LIMIT 1
        ');
        $stmt->execute([$pageId, $userId, $userId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function isOwner($db, $pageId, $userId)
    {
        $stmt = $db->prepare('SELECT 1 FROM pages WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$pageId, $userId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function canManageGroup($db, $groupId, $userId)
    {
        if (Plataforma::esAdmin($db, $userId)) {
            return self::existe($db, 'link_groups', $groupId);
        }

        $stmt = $db->prepare('
            SELECT 1 FROM link_groups lg
            JOIN pages p ON lg.page_id = p.id
            WHERE lg.id = ? AND (
                p.user_id = ?
                OR EXISTS (
                    SELECT 1 FROM page_admins pa
                    WHERE pa.page_id = p.id AND pa.user_id = ? AND pa.status = "accepted"
                )
            ) LIMIT 1
        ');
        $stmt->execute([$groupId, $userId, $userId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function canManageLink($db, $linkId, $userId)
    {
        if (Plataforma::esAdmin($db, $userId)) {
            return self::existe($db, 'links', $linkId);
        }

        $stmt = $db->prepare('
            SELECT 1 FROM links l
            JOIN link_groups lg ON l.group_id = lg.id
            JOIN pages p ON lg.page_id = p.id
            WHERE l.id = ? AND (
                p.user_id = ?
                OR EXISTS (
                    SELECT 1 FROM page_admins pa
                    WHERE pa.page_id = p.id AND pa.user_id = ? AND pa.status = "accepted"
                )
            ) LIMIT 1
        ');
        $stmt->execute([$linkId, $userId, $userId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Para la plataforma la pregunta ya no es de quién es, sino si existe.
     * Igual hay que hacerla: sin esto un id inventado daría permiso y el
     * handler seguiría de largo hasta romperse más adelante.
     */
    private static function existePagina($db, $pageId)
    {
        return self::existe($db, 'pages', $pageId);
    }

    /** @param string $tabla Nombre fijo del código, nunca entrada del usuario. */
    private static function existe($db, $tabla, $id)
    {
        $stmt = $db->prepare("SELECT 1 FROM $tabla WHERE id = ? LIMIT 1");
        $stmt->execute([(int) $id]);

        return (bool) $stmt->fetchColumn();
    }
}
