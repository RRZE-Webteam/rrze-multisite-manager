<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class StatusWidget extends Widgets {
    public function getId(): string {
        return 'status';
    }

    public function getTitle(): string {
        return __('Statusverteilung', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Verteilung der Site-Zustände innerhalb dieses Netzwerks.', 'rrze-multisite-manager');
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-size-fluid-chart';
    }

    protected function getTemplateName(): string {
        return 'status-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        return [
            'items' => $dashboardData['status_distribution'] ?? [],
            'empty_message' => __('Keine Statusdaten vorhanden.', 'rrze-multisite-manager'),
            'status_explanations' => [
                [
                    'label' => __('Aktiv und öffentlich', 'rrze-multisite-manager'),
                    'text' => __('Status-Badges im System: „Aktiv“ und „Öffentlich“ (`archived=0`, `spam=0`, `deleted=0`, `public=1`). Frontend erreichbar: ja. Site-Dashboard erreichbar: ja. Für Superadmins erreichbar: ja. Für Suchmaschinen grundsätzlich vorgesehen.', 'rrze-multisite-manager'),
                ],
                [
                    'label' => __('Aktiv, Suchmaschinen ausgeschlossen', 'rrze-multisite-manager'),
                    'text' => __('Status-Badges im System: „Aktiv“ und „Nicht öffentlich“ (`archived=0`, `spam=0`, `deleted=0`, `public=0`). Frontend erreichbar: ja. Site-Dashboard erreichbar: ja. Für Superadmins erreichbar: ja. Nicht für Suchmaschinen vorgesehen bzw. nicht öffentlich indexierbar.', 'rrze-multisite-manager'),
                ],
                [
                    'label' => __('Archiviert', 'rrze-multisite-manager'),
                    'text' => __('Status-Badge im System: „Archiviert“ (`archived=1`). Frontend erreichbar: nein. Site-Dashboard für normale Bearbeiter: nein. Für Superadmins erreichbar: ja. Im Core gesperrt.', 'rrze-multisite-manager'),
                ],
                [
                    'label' => __('Gesperrt (Spam)', 'rrze-multisite-manager'),
                    'text' => __('Status-Badge im System: „Gesperrt“ (`spam=1`). Frontend erreichbar: nein. Site-Dashboard für normale Bearbeiter: nein. Für Superadmins erreichbar: ja. Im Core gesperrt wie archiviert, aber als Missbrauchs-/Sperrfall markiert.', 'rrze-multisite-manager'),
                ],
                [
                    'label' => __('Zum Löschen markiert', 'rrze-multisite-manager'),
                    'text' => __('Status-Badge im System: „Gelöscht“ (`deleted=1`). Frontend erreichbar: nein. Site-Dashboard für normale Bearbeiter: nein. Für Superadmins erreichbar: ja. Lösch-Flag gesetzt, aber keine automatische Endlöschung.', 'rrze-multisite-manager'),
                ],
            ],
        ];
    }
}
