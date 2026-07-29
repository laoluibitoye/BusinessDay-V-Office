<?php
// lib/IrsFlow.php — IRS Workflow Engine helper

// Plain-English owner names for the Approval Progress panel. Stage labels say
// what happens; this says who it is waiting on, until they action it.
if (!defined('IRS_STAGE_OWNERS')) {
    define('IRS_STAGE_OWNERS', [
        'head_accounts'    => 'Head Accounts',
        'accountant'       => 'Accountant',
        'md'               => 'MD',
        'bdm'              => 'BDM',
        'hr'               => 'Human Resources',
        'head_outsourcing' => 'Head Outsourcing',
        'head_compliance'  => 'Head Compliance',
        'head_training'    => 'Head Training',
        'head_cso'         => 'Head Client Service Ops',
    ]);
}

class IrsFlow {

    // Roles that can see ALL requests (others see only their own)
    const VIEW_ALL_ROLES = ['accountant', 'head_accounts', 'md', 'bdm', 'head_it'];

    // Stages from which requester may withdraw their own request
    const WITHDRAWABLE_STAGES = ['draft', 'pending_corrections', 'pending_hod_accounts', 'pending_eligibility', 'pending_accountant'];

    // Roles allowed to raise a Payment Request (head_accounts approves — does not raise)
    const PAYMENT_RAISER_ROLES = ['accountant', 'head_it'];

    // ── Visibility ──────────────────────────────────────────────────────────────

    public static function canView(array $user, array $req): bool {
        if (in_array($user['role'], self::VIEW_ALL_ROLES)) return true;
        return (int)$req['requester_id'] === (int)$user['id'];
    }

    public static function canViewAll(array $user): bool {
        return in_array($user['role'], self::VIEW_ALL_ROLES);
    }

    // ── Stage definitions ────────────────────────────────────────────────────────

    public static function getStages(PDO $db, string $type): array {
        try {
            $s = $db->prepare("SELECT * FROM irs_flow_stages WHERE request_type=? ORDER BY stage_order ASC");
            $s->execute([$type]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { return []; }
    }

    public static function getStage(PDO $db, string $type, string $stage): ?array {
        try {
            $s = $db->prepare("SELECT * FROM irs_flow_stages WHERE request_type=? AND stage_code=?");
            $s->execute([$type, $stage]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Exception $e) { return null; }
    }

    public static function stageLabel(PDO $db, string $type, string $stage): string {
        static $cache = [];
        $key = $type . ':' . $stage;
        if (isset($cache[$key])) return $cache[$key];
        $row = self::getStage($db, $type, $stage);
        $cache[$key] = $row ? $row['stage_label'] : ucwords(str_replace('_', ' ', $stage));
        return $cache[$key];
    }

    // ── Actor checks ─────────────────────────────────────────────────────────────

    public static function isActor(PDO $db, array $user, string $type, string $stage): bool {
        $row = self::getStage($db, $type, $stage);
        if (!$row) return false;
        $roles = json_decode($row['actor_roles'], true);
        return is_array($roles) && in_array($user['role'], $roles);
    }

    // ── Transitions ──────────────────────────────────────────────────────────────

    public static function getTransitions(PDO $db, string $type, string $stage): array {
        try {
            $s = $db->prepare("SELECT * FROM irs_flow_transitions WHERE request_type=? AND from_stage=? ORDER BY sort_order ASC");
            $s->execute([$type, $stage]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { return []; }
    }

    public static function getTransition(PDO $db, string $type, string $stage, string $actionCode): ?array {
        try {
            $s = $db->prepare("SELECT * FROM irs_flow_transitions WHERE request_type=? AND from_stage=? AND action_code=?");
            $s->execute([$type, $stage, $actionCode]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Exception $e) { return null; }
    }

    // Returns transitions available to this user at the current stage
    public static function getAvailableActions(PDO $db, array $user, string $type, string $stage): array {
        if (!self::isActor($db, $user, $type, $stage)) return [];
        return self::getTransitions($db, $type, $stage);
    }

    // ── Initial stage after submission ───────────────────────────────────────────

    public static function initialStage(string $type): string {
        $map = [
            'requisition' => 'pending_hod_accounts',
            'caution'     => 'pending_eligibility',
            'payment'     => 'pending_hod_accounts',
            'petty_cash'  => 'pending_accountant',
            'retirement'  => 'pending_hod_accounts',
        ];
        return $map[$type] ?? 'pending_hod_accounts';
    }

    // ── Status display helpers ────────────────────────────────────────────────────

    public static function allStageCodes(): array {
        return [
            'draft', 'pending_corrections', 'pending_eligibility', 'pending_accountant',
            'pending_hod_accounts', 'pending_hod_accounts_payment', 'pending_md',
            'pending_payment', 'pending_payment_approval',
            'pending_post', 'completed', 'rejected',
        ];
    }

    /**
     * Fallback stage labels. These mirror irs_flow_stages.stage_label, which is
     * the authority — keep the two in sync (see database/irs_stage_rename.sql).
     * Naming rule: the label says what happens; the department that owns it is
     * rendered separately, so no acronyms and no role names here.
     */
    public static function defaultStageLabel(string $stage): string {
        $labels = [
            'draft'                        => 'Draft',
            'pending_corrections'          => 'Returned for Correction',
            'pending_eligibility'          => 'Eligibility Review',
            'pending_accountant'           => 'Cash Disbursement',
            'pending_hod_accounts'         => 'Accounts Review',
            'pending_hod_accounts_payment' => 'Payment Verification',
            'pending_md'                   => 'Management Approval',
            'pending_payment'              => 'Payment Prepared',
            'pending_payment_approval'     => 'Payment Authorisation',
            'pending_post'                 => 'Posted to Sage',
            'completed'                    => 'Completed',
            'rejected'                     => 'Rejected',
        ];
        return $labels[$stage] ?? ucwords(str_replace('_', ' ', $stage));
    }

    public static function stageColor(string $stage): string {
        $colors = [
            'draft'                    => '#94a3b8',
            'pending_corrections'      => '#f59e0b',
            'pending_eligibility'      => '#f59e0b',
            'pending_accountant'       => '#f59e0b',
            'pending_hod_accounts'     => '#f97316',
            'pending_md'               => '#3b82f6',
            'pending_payment'          => '#8b5cf6',
            'pending_payment_approval' => '#0891b2',
            'pending_post'             => '#059669',
            'completed'                => '#059669',
            'rejected'                 => '#ef4444',
        ];
        return $colors[$stage] ?? '#94a3b8';
    }

    public static function isTerminal(string $stage): bool {
        return in_array($stage, ['completed', 'rejected']);
    }
}
