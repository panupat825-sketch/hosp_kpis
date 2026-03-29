<?php

class OAuthUserRepositoryV2
{
    private $conn;

    public function __construct($conn)
    {
        $this->conn = $conn;
    }

    public function saveFromProviderProfile($providerId, $profile)
    {
        if ($providerId === '') {
            return array(
                'success' => false,
                'error' => 'Provider ID is empty',
            );
        }

        $stmt = $this->conn->prepare("SELECT id FROM app_user WHERE provider_id = ? LIMIT 1");
        if (!$stmt) {
            return array('success' => false, 'error' => $this->conn->error);
        }

        $stmt->bind_param('s', $providerId);
        $stmt->execute();
        $res = $stmt->get_result();
        $existing = $res->num_rows ? $res->fetch_assoc() : null;
        $stmt->close();

        $healthAccountId = isset($profile['account_id']) ? (string)$profile['account_id'] : null;
        $nameTh = isset($profile['name_th']) ? (string)$profile['name_th'] : null;
        $nameEng = isset($profile['name_eng']) ? (string)$profile['name_eng'] : null;
        $positionName = isset($profile['position']) ? (string)$profile['position'] : (isset($profile['position_name']) ? (string)$profile['position_name'] : null);
        $positionType = isset($profile['position_type']) ? (string)$profile['position_type'] : null;

        if ($existing) {
            $userId = (int)$existing['id'];
            $stmt = $this->conn->prepare(
                "UPDATE app_user
                 SET health_account_id = ?, name_th = ?, name_eng = ?, position_name = ?, position_type = ?, last_login_at = NOW()
                 WHERE id = ?"
            );
            if (!$stmt) {
                return array('success' => false, 'error' => $this->conn->error);
            }
            $stmt->bind_param('sssssi', $healthAccountId, $nameTh, $nameEng, $positionName, $positionType, $userId);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $this->conn->prepare(
                "INSERT INTO app_user
                 (provider_id, health_account_id, name_th, name_eng, position_name, position_type, is_active, first_login_at, last_login_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())"
            );
            if (!$stmt) {
                return array('success' => false, 'error' => $this->conn->error);
            }
            $stmt->bind_param('ssssss', $providerId, $healthAccountId, $nameTh, $nameEng, $positionName, $positionType);
            $stmt->execute();
            $userId = (int)$this->conn->insert_id;
            $stmt->close();

            $roleStmt = $this->conn->prepare(
                "INSERT IGNORE INTO app_user_role (app_user_id, role, assigned_by, assigned_at, is_active)
                 VALUES (?, 'user', 'system', NOW(), 1)"
            );
            if ($roleStmt) {
                $roleStmt->bind_param('i', $userId);
                $roleStmt->execute();
                $roleStmt->close();
            }
        }

        $this->syncOrganizations($userId, isset($profile['organization']) && is_array($profile['organization']) ? $profile['organization'] : array());

        return array(
            'success' => true,
            'app_user_id' => $userId,
        );
    }

    public function saveHealthOnlyUser($healthAccountId, $fallbackName)
    {
        $providerId = 'HEALTHONLY:' . $healthAccountId;
        $fallbackName = $fallbackName !== '' ? $fallbackName : ('Health ID ' . $healthAccountId);

        $stmt = $this->conn->prepare("SELECT id FROM app_user WHERE provider_id = ? LIMIT 1");
        if (!$stmt) {
            return array('success' => false, 'error' => $this->conn->error);
        }
        $stmt->bind_param('s', $providerId);
        $stmt->execute();
        $res = $stmt->get_result();
        $existing = $res->num_rows ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($existing) {
            $userId = (int)$existing['id'];
            $stmt = $this->conn->prepare(
                "UPDATE app_user
                 SET health_account_id = ?, name_th = ?, last_login_at = NOW()
                 WHERE id = ?"
            );
            if (!$stmt) {
                return array('success' => false, 'error' => $this->conn->error);
            }
            $stmt->bind_param('ssi', $healthAccountId, $fallbackName, $userId);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $this->conn->prepare(
                "INSERT INTO app_user
                 (provider_id, health_account_id, name_th, name_eng, position_name, position_type, is_active, first_login_at, last_login_at)
                 VALUES (?, ?, ?, '', 'Health ID Only', 'fallback', 1, NOW(), NOW())"
            );
            if (!$stmt) {
                return array('success' => false, 'error' => $this->conn->error);
            }
            $stmt->bind_param('sss', $providerId, $healthAccountId, $fallbackName);
            $stmt->execute();
            $userId = (int)$this->conn->insert_id;
            $stmt->close();

            $roleStmt = $this->conn->prepare(
                "INSERT IGNORE INTO app_user_role (app_user_id, role, assigned_by, assigned_at, is_active)
                 VALUES (?, 'user', 'system', NOW(), 1)"
            );
            if ($roleStmt) {
                $roleStmt->bind_param('i', $userId);
                $roleStmt->execute();
                $roleStmt->close();
            }
        }

        return array(
            'success' => true,
            'app_user_id' => $userId,
            'provider_id' => $providerId,
        );
    }

    public function fetchSessionUser($appUserId)
    {
        $stmt = $this->conn->prepare("SELECT id, provider_id, name_th, name_eng, position_name FROM app_user WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $appUserId);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res->num_rows ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$user) {
            return null;
        }

        $orgStmt = $this->conn->prepare("SELECT hcode, hname_th FROM app_user_org WHERE app_user_id = ? AND is_default = 1 LIMIT 1");
        $org = null;
        if ($orgStmt) {
            $orgStmt->bind_param('i', $appUserId);
            $orgStmt->execute();
            $orgRes = $orgStmt->get_result();
            $org = $orgRes->num_rows ? $orgRes->fetch_assoc() : null;
            $orgStmt->close();
        }

        return array(
            'app_user_id' => (int)$user['id'],
            'provider_id' => (string)$user['provider_id'],
            'name_th' => (string)$user['name_th'],
            'name_eng' => (string)$user['name_eng'],
            'position_name' => (string)$user['position_name'],
            'hcode' => $org ? (string)$org['hcode'] : '',
            'hname_th' => $org ? (string)$org['hname_th'] : '',
        );
    }

    private function syncOrganizations($appUserId, $organizations)
    {
        $deleteStmt = $this->conn->prepare("DELETE FROM app_user_org WHERE app_user_id = ?");
        if ($deleteStmt) {
            $deleteStmt->bind_param('i', $appUserId);
            $deleteStmt->execute();
            $deleteStmt->close();
        }

        $isDefault = 1;
        foreach ($organizations as $org) {
            if (!is_array($org) || empty($org['hcode'])) {
                continue;
            }

            $hcode = substr((string)$org['hcode'], 0, 20);
            $hnameTh = isset($org['hname_th']) ? substr((string)$org['hname_th'], 0, 255) : null;
            $hnameEng = isset($org['hname_eng']) ? substr((string)$org['hname_eng'], 0, 255) : null;
            $zoneId = isset($org['level']) ? substr((string)$org['level'], 0, 50) : null;

            $stmt = $this->conn->prepare(
                "INSERT INTO app_user_org
                 (app_user_id, hcode, hname_th, hname_eng, zone_id, is_default, synced_from_provider, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, 1, 1)"
            );
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param('issssi', $appUserId, $hcode, $hnameTh, $hnameEng, $zoneId, $isDefault);
            $stmt->execute();
            $stmt->close();
            $isDefault = 0;
        }
    }
}
