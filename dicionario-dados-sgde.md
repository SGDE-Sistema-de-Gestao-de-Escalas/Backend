# SGDE — Dicionário de Dados Completo da Base de Dados

> **Documento de Suporte Técnico / Anexo do Relatório Final**  
> Especificação exaustiva das 29 tabelas da base de dados relacional (20 tabelas de Domínio Escolar e 9 tabelas de Infraestrutura/Framework Laravel).

---

### Tabela: `roles`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | uuid()    · NN |
| **—** | `name` | string()    · NN |
| **UK** | `slug` | string()    · NN |
| **—** | `description` | text()    · NULL |
| **—** | `created_at` | timestamp()    · NULL |
| **—** | `updated_at` | timestamp()    · NULL |


### Tabela: `users`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | uuid()    · NN |
| **FK** | `role_id` | uuid()    · NN |
| **—** | `first_name` | string()    · NN |
| **—** | `last_name` | string()    · NN |
| **UK** | `email` | string()    · NN |
| **—** | `email_verified_at` | timestamp()    · NULL |
| **—** | `password` | string()    · NN |
| **—** | `provider` | string()    · NULL |
| **—** | `provider_id` | string()    · NULL |
| **—** | `remember_token` | string(100)    · NULL |
| **—** | `is_active` | boolean()    · NN; default true |
| **—** | `anonymized_at` | timestamp()    · NULL |
| **—** | `created_at` | timestamp()    · NULL |
| **—** | `updated_at` | timestamp()    · NULL |
| **—** | `deleted_at` | timestamp()    · NULL |


### Tabela: `personal_access_tokens`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | uuid()    · NN |
| **—** | `tokenable_type` | string()    · NN |
| **FK** | `tokenable_id` | uuid()    · NN |
| **—** | `name` | text()    · NN |
| **UK** | `token` | string(64)    · NN |
| **—** | `abilities` | text()    · NULL |
| **—** | `last_used_at` | timestamp()    · NULL |
| **—** | `expires_at` | timestamp()    · NULL |
| **—** | `created_at` | timestamp()    · NULL |
| **—** | `updated_at` | timestamp()    · NULL |


### Tabela: `password_reset_tokens`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `email` | string()    · NN |
| **—** | `token` | string()    · NN |
| **—** | `created_at` | timestamp()    · NULL |


### Tabela: `sessions`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | string()    · NN |
| **—** | `user_id` | uuid()    · NULL |
| **—** | `ip_address` | string(45)    · NULL |
| **—** | `user_agent` | text()    · NULL |
| **—** | `payload` | longText()    · NN |
| **—** | `last_activity` | integer()    · NN |


### Tabela: `schools`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | uuid()    · NN |
| **—** | `name` | string()    · NN |
| **UK** | `acronym` | string()    · NULL |
| **—** | `address` | string()    · NULL |
| **—** | `phone` | string()    · NULL |
| **UK** | `email` | string()    · NULL |
| **—** | `active` | boolean()    · NN; default true |
| **—** | `created_at` | timestamp()    · NULL |
| **—** | `updated_at` | timestamp()    · NULL |


### Tabela: `school_operating_rules`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | uuid()    · NN |
| **FK** | `school_id` | uuid()    · NN |
| **—** | `valid_from` | date()    · NN |
| **—** | `created_at` | timestamp()    · NULL |
| **—** | `updated_at` | timestamp()    · NULL |


### Tabela: `school_operating_rule_days`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | uuid()    · NN |
| **FK** | `school_operating_rule_id` | uuid()    · NN |
| **—** | `day_of_week` | unsignedTinyInteger()    · NN |
| **—** | `created_at` | timestamp()    · NULL |
| **—** | `updated_at` | timestamp()    · NULL |
| **—** | `opening_time` | time()    · NN |
| **—** | `closing_time` | time()    · NN |
| **—** | `lunch_start` | time()    · NN |
| **—** | `lunch_end` | time()    · NN |
| **—** | `lunch_duration_minutes` | unsignedSmallInteger()    · NN |


### Tabela: `school_operating_exceptions`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | uuid()    · NN |
| **FK** | `school_id` | uuid()    · NN |
| **—** | `name` | string()    · NN |
| **—** | `is_closed` | boolean()    · NN; default false |
| **—** | `opening_time` | time()    · NULL |
| **—** | `closing_time` | time()    · NULL |
| **—** | `lunch_start` | time()    · NULL |
| **—** | `lunch_end` | time()    · NULL |
| **—** | `lunch_duration_minutes` | unsignedSmallInteger()    · NULL |
| **—** | `valid_from` | date()    · NN |
| **—** | `valid_until` | date()    · NN |
| **—** | `created_at` | timestamp()    · NULL |
| **—** | `updated_at` | timestamp()    · NULL |


### Tabela: `holidays`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | uuid()    · NN |
| **—** | `name` | string()    · NN |
| **UK** | `date` | date()    · NN |
| **—** | `description` | text()    · NULL |
| **—** | `created_at` | timestamp()    · NULL |
| **—** | `updated_at` | timestamp()    · NULL |


### Tabela: `assistants`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | uuid()    · NN |
| **FK** | `user_id` | uuid()    · NN |
| **FK** | `school_id` | uuid()    · NN |
| **UK** | `internal_number` | string()    · NN |
| **UK** | `phone` | string()    · NULL |
| **UK** | `nif` | string(9)    · NULL |
| **UK** | `social_security_number` | string(11)    · NULL |
| **—** | `birth_date` | date()    · NULL |
| **—** | `admission_date` | date()    · NULL |
| **—** | `criminal_record_name` | string()    · NULL |
| **—** | `criminal_record_path` | string()    · NULL |
| **—** | `criminal_record_expiry` | date()    · NULL |
| **—** | `address_street` | string()    · NULL |
| **—** | `address_zip_code` | string()    · NULL |
| **—** | `address_city` | string()    · NULL |
| **—** | `emergency_contact_name` | string()    · NULL |
| **—** | `emergency_contact_phone` | string()    · NULL |
| **—** | `emergency_contact_kinship` | string()    · NULL |
| **—** | `available_for_transfer` | boolean()    · NN; default false |
| **—** | `is_anonymized` | boolean()    · NN; default false |
| **—** | `created_at` | timestamp()    · NULL |
| **—** | `updated_at` | timestamp()    · NULL |
| **—** | `deleted_at` | timestamp()    · NULL |


### Tabela: `assistant_exceptions`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | uuid()    · NN |
| **FK** | `assistant_id` | uuid()    · NN |
| **—** | `type` | string()    · NN |
| **—** | `description` | text()    · NULL |
| **—** | `valid_from` | date()    · NN |
| **—** | `valid_until` | date()    · NULL |
| **—** | `start_time` | time()    · NULL |
| **—** | `end_time` | time()    · NULL |
| **—** | `days` | json()    · NULL |
| **—** | `lunch_enabled` | boolean()    · NN; default false |
| **—** | `lunch_start` | time()    · NULL |
| **—** | `lunch_end` | time()    · NULL |
| **—** | `lunch_duration_minutes` | unsignedSmallInteger()    · NULL |
| **—** | `created_at` | timestamp()    · NULL |
| **—** | `updated_at` | timestamp()    · NULL |
| **—** | `deleted_at` | timestamp()    · NULL |


### Tabela: `assistant_temporary_assignments`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | uuid()    · NN |
| **FK** | `assistant_id` | uuid()    · NN |
| **FK** | `origin_school_id` | uuid()    · NN |
| **FK** | `destination_school_id` | uuid()    · NN |
| **FK** | `created_by_user_id` | uuid()    · NN |
| **FK** | `validated_by_user_id` | uuid()    · NULL |
| **—** | `valid_from` | date()    · NN |
| **—** | `valid_until` | date()    · NULL |
| **—** | `notes` | text()    · NULL |
| **—** | `created_at` | timestamp()    · NULL |
| **—** | `updated_at` | timestamp()    · NULL |
| **—** | `start_time` | time()    · NULL |
| **—** | `end_time` | time()    · NULL |


### Tabela: `assistant_schedule_profiles`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | uuid()    · NN |
| **FK** | `assistant_id` | uuid()    · NN |
| **—** | `type` | enum([&#x27;fixo&#x27;, &#x27;rotativo&#x27;])    · NN |
| **—** | `rotation_period` | enum([&#x27;weekly&#x27;, &#x27;biweekly&#x27;, &#x27;monthly&#x27;])    · NULL |
| **—** | `starts_with` | enum([&#x27;A&#x27;, &#x27;B&#x27;])    · NULL |
| **—** | `valid_from` | date()    · NN |
| **—** | `valid_until` | date()    · NULL |
| **—** | `created_at` | timestamp()    · NULL |
| **—** | `updated_at` | timestamp()    · NULL |


### Tabela: `assistant_schedule_shifts`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | uuid()    · NN |
| **FK** | `assistant_schedule_profile_id` | uuid()    · NN |
| **—** | `shift_label` | enum([&#x27;A&#x27;, &#x27;B&#x27;])    · NN |
| **—** | `entry_time` | time()    · NN |
| **—** | `exit_time` | time()    · NN |
| **—** | `lunch_enabled` | boolean()    · NN; default true |
| **—** | `lunch_start` | time()    · NULL |
| **—** | `lunch_end` | time()    · NULL |
| **—** | `lunch_duration_minutes` | unsignedSmallInteger()    · NULL |
| **—** | `created_at` | timestamp()    · NULL |
| **—** | `updated_at` | timestamp()    · NULL |


### Tabela: `assistant_schedule_shift_days`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | uuid()    · NN |
| **FK** | `assistant_schedule_shift_id` | uuid()    · NN |
| **—** | `day_of_week` | unsignedTinyInteger()    · NN |
| **—** | `created_at` | timestamp()    · NULL |
| **—** | `updated_at` | timestamp()    · NULL |


### Tabela: `activity_types`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | uuid()    · NN |
| **FK** | `school_id` | uuid()    · NN |
| **—** | `name` | string()    · NN |
| **—** | `color` | string(7)    · NN |
| **—** | `is_system` | boolean()    · NN; default false |
| **—** | `active` | boolean()    · NN; default true |
| **—** | `created_at` | timestamp()    · NULL |
| **—** | `updated_at` | timestamp()    · NULL |
| **—** | `is_fallback` | boolean()    · NN; default false |


### Tabela: `schedule_rules`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | uuid()    · NN |
| **FK** | `school_id` | uuid()    · NN |
| **FK** | `activity_type_id` | uuid()    · NN |
| **—** | `period_start` | time()    · NN |
| **—** | `period_end` | time()    · NN |
| **—** | `min` | unsignedInteger()    · NN |
| **—** | `type` | string()    · NN |
| **—** | `valid_from` | date()    · NN |
| **—** | `created_at` | timestamp()    · NULL |
| **—** | `updated_at` | timestamp()    · NULL |
| **—** | `valid_until` | date()    · NULL |
| **—** | `days_of_week` | json()    · NULL |


### Tabela: `schedule_rule_assistant`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK, FK** | `schedule_rule_id` | uuid()    · NN |
| **PK, FK** | `assistant_id` | uuid()    · NN |
| **—** | `priority` | unsignedInteger()    · NN; default 1 |


### Tabela: `schedules`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | uuid()    · NN |
| **FK** | `assistant_id` | uuid()    · NN |
| **FK** | `school_id` | uuid()    · NN |
| **—** | `week_start` | date()    · NN |
| **—** | `week_end` | date()    · NN |
| **—** | `status` | enum([&#x27;draft&#x27;, &#x27;published&#x27;, &#x27;archived&#x27;])    · NN; default &#x27;draft&#x27; |
| **FK** | `assistant_schedule_profile_id` | uuid()    · NULL |
| **—** | `generated_at` | timestamp()    · NULL |
| **—** | `published_at` | timestamp()    · NULL |
| **—** | `created_at` | timestamp()    · NULL |
| **—** | `updated_at` | timestamp()    · NULL |
| **—** | `deleted_at` | timestamp()    · NULL |


### Tabela: `schedule_entries`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | uuid()    · NN |
| **FK** | `schedule_id` | uuid()    · NN |
| **FK** | `activity_type_id` | uuid()    · NN |
| **—** | `date` | date()    · NN |
| **—** | `start_time` | time()    · NN |
| **—** | `end_time` | time()    · NN |
| **—** | `is_locked` | boolean()    · NN; default false |
| **—** | `created_at` | timestamp()    · NULL |
| **—** | `updated_at` | timestamp()    · NULL |
| **—** | `activity_name` | string()    · NULL |
| **FK** | `schedule_rule_id` | uuid()    · NULL |
| **FK** | `replaced_assistant_id` | uuid()    · NULL |
| **—** | `replacement_reason` | string(64)    · NULL |


### Tabela: `absence_types`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | uuid()    · NN |
| **—** | `name` | string()    · NN |
| **—** | `requires_document` | boolean()    · NN; default false |
| **—** | `created_at` | timestamp()    · NULL |
| **—** | `updated_at` | timestamp()    · NULL |


### Tabela: `absences`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | uuid()    · NN |
| **FK** | `assistant_id` | uuid()    · NN |
| **FK** | `absence_type_id` | uuid()    · NN |
| **FK** | `created_by_user_id` | uuid()    · NULL |
| **FK** | `justified_by_user_id` | uuid()    · NULL |
| **—** | `valid_from` | date()    · NN |
| **—** | `valid_until` | date()    · NULL |
| **—** | `start_time` | time()    · NULL |
| **—** | `end_time` | time()    · NULL |
| **—** | `total_days` | unsignedSmallInteger()    · NN; default 1 |
| **—** | `justification` | text()    · NULL |
| **—** | `document_name` | string()    · NULL |
| **—** | `document_path` | string()    · NULL |
| **—** | `status` | enum([&#x27;pending&#x27;, &#x27;justified&#x27;, &#x27;unjustified&#x27;])    · NN; default &#x27;pending&#x27; |
| **—** | `notes` | text()    · NULL |
| **—** | `created_at` | timestamp()    · NULL |
| **—** | `updated_at` | timestamp()    · NULL |
| **—** | `deleted_at` | timestamp()    · NULL |


### Tabela: `notifications`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | uuid()    · NN |
| **FK** | `user_id` | uuid()    · NN |
| **—** | `type` | string()    · NN |
| **—** | `title` | string()    · NN |
| **—** | `body` | text()    · NULL |
| **—** | `data` | json()    · NULL |
| **—** | `read_at` | timestamp()    · NULL |
| **—** | `created_at` | timestamp()    · NULL |
| **—** | `updated_at` | timestamp()    · NULL |
| **FK** | `school_id` | uuid()    · NULL |
| **—** | `event` | string(64)    · NULL |
| **—** | `dedupe_key` | string(191)    · NULL |


### Tabela: `cache`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `key` | string()    · NN |
| **—** | `value` | mediumText()    · NN |
| **—** | `expiration` | integer()    · NN |


### Tabela: `cache_locks`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `key` | string()    · NN |
| **—** | `owner` | string()    · NN |
| **—** | `expiration` | integer()    · NN |


### Tabela: `jobs`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | uuid()    · NN |
| **—** | `queue` | string()    · NN |
| **—** | `payload` | longText()    · NN |
| **—** | `attempts` | unsignedTinyInteger()    · NN |
| **—** | `reserved_at` | unsignedInteger()    · NULL |
| **—** | `available_at` | unsignedInteger()    · NN |
| **—** | `created_at` | unsignedInteger()    · NN |


### Tabela: `job_batches`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | string()    · NN |
| **—** | `name` | string()    · NN |
| **—** | `total_jobs` | integer()    · NN |
| **—** | `pending_jobs` | integer()    · NN |
| **—** | `failed_jobs` | integer()    · NN |
| **—** | `failed_job_ids` | longText()    · NN |
| **—** | `options` | mediumText()    · NULL |
| **—** | `cancelled_at` | integer()    · NULL |
| **—** | `created_at` | integer()    · NN |
| **—** | `finished_at` | integer()    · NULL |


### Tabela: `failed_jobs`

| Chave | Campo | Tipo e Restrições |
| :---: | :--- | :--- |
| **PK** | `id` | id()    · NN; default autoincremento |
| **UK** | `uuid` | string()    · NN |
| **—** | `connection` | text()    · NN |
| **—** | `queue` | text()    · NN |
| **—** | `payload` | longText()    · NN |
| **—** | `exception` | longText()    · NN |
| **—** | `failed_at` | timestamp()    · NN; default CURRENT_TIMESTAMP |

