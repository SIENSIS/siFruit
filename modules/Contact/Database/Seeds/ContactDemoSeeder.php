<?php

declare(strict_types=1);

namespace Modules\Contact\Database\Seeds;

use CodeIgniter\I18n\Time;
use Modules\Core\Demo\DemoData;
use Modules\Core\Demo\DemoSeeder;

/**
 * Demo data of the Contact module (`php spark modules:demo Contact`): three topics with names in
 * Catalan, Spanish and English ("Expedició de títols" goes to the demo editor and admin users and
 * an address, "Informació general" to the `editor` group, "Direcció" to the `admin` group) and
 * three messages, one per status. Every row goes through `$demo`.
 */
final class ContactDemoSeeder extends DemoSeeder
{
    public function load(DemoData $demo): void
    {
        $now = Time::now();

        $editor = $this->userId('editor@example.com');
        $admin  = $this->userId('admin@example.com');

        // Topics (D-48-03): specific users, a group and/or external addresses.
        $titles = $this->recipient($demo, 1, 'secretaria@example.com', null, array_values(array_filter([$editor, $admin])), ['ca' => 'Expedició de títols', 'es' => 'Expedición de títulos', 'en' => 'Degree certificates']);
        $office = $this->recipient($demo, 2, null, 'editor', [], ['ca' => 'Informació general', 'es' => 'Información general', 'en' => 'General information']);
        $head   = $this->recipient($demo, 3, null, 'admin', [], ['ca' => 'Direcció', 'es' => 'Dirección', 'en' => 'Management']);

        $messages = [
            [$office, 'Informació general', 'Marta Riera', 'marta@example.com', 'Horari de matrícula', "Bon dia,\nvoldria saber l'horari de matrícula del mes vinent.\nGràcies.", 'ca', 'new', 1],
            [$head, 'Direcció', 'Joan Puig', 'joan@example.com', 'Proposta de col·laboració', "Hola,\nsom una entitat del barri i voldríem proposar-vos una activitat conjunta.", 'ca', 'answered', 4],
            [$titles, 'Expedició de títols', 'Laura Gómez', 'laura@example.com', 'Certificado', 'Necesito un certificado de asistencia del curso pasado.', 'es', 'archived', 20],
        ];

        foreach ($messages as [$recipient, $name, $from, $email, $subject, $text, $locale, $status, $days]) {
            $date = $now->subDays($days)->toDateTimeString();
            $demo->insert('contact_messages', [
                'recipient_id'   => $recipient,
                'recipient_name' => $name,
                'name'           => $from,
                'email'          => $email,
                'subject'        => $subject,
                'message'        => $text,
                'locale'         => $locale,
                'status'         => $status,
                'answered_at'    => $status === 'answered' ? $date : null,
                'created_at'     => $date,
                'updated_at'     => $date,
            ]);
        }
    }

    /**
     * @param list<int>             $users the topic's specific users
     * @param array<string, string> $names locale => name
     */
    private function recipient(DemoData $demo, int $position, ?string $emails, ?string $group, array $users, array $names): int
    {
        $now = Time::now()->toDateTimeString();
        $id  = (int) $demo->insert('contact_recipients', [
            'emails'     => $emails,
            'group_name' => $group,
            'position'   => $position,
            'active'     => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($users as $user) {
            $demo->insert('contact_recipient_users', ['recipient_id' => $id, 'user_id' => $user]);
        }

        foreach ($names as $locale => $name) {
            $demo->insert('contact_recipient_translations', ['recipient_id' => $id, 'locale' => $locale, 'name' => $name]);
        }

        return $id;
    }

    private function userId(string $email): ?int
    {
        $row = db_connect()->table('auth_identities')->select('user_id')->where('type', 'email_password')->where('secret', $email)->get()->getRowArray();

        return is_array($row) ? (int) $row['user_id'] : null;
    }
}
