<?php

// Helpers de autorización para administración de páginas.
// "Gestionar" = ser dueño (pages.user_id) O administrador aceptado (page_admins.status = 'accepted').
// Acciones reservadas al dueño (borrar página, gestionar admins) deben usar isOwner().

class PageAccess
{
    public static function canManage($db, $pageId, $userId)
    {
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
}
