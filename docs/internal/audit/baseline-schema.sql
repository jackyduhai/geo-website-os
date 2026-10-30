-- GEO Website OS Baseline Schema
-- Source: SQLite (database/database.sqlite)
-- Generated: 2026-09-18 00:37:46
-- Table count: 23

-- ===== audit_logs (rows: 26) =====
CREATE TABLE "audit_logs" ("id" integer primary key autoincrement not null, "user_id" integer, "action" varchar not null, "target_type" varchar, "target_id" integer, "summary" varchar, "detail" text, "ip" varchar, "created_at" datetime, "updated_at" datetime);

-- INDEX: audit_logs_created_at_index
CREATE INDEX "audit_logs_created_at_index" on "audit_logs" ("created_at");
-- INDEX: audit_logs_target_type_target_id_index
CREATE INDEX "audit_logs_target_type_target_id_index" on "audit_logs" ("target_type", "target_id");

-- ===== banners (rows: 3) =====
CREATE TABLE "banners" ("id" integer primary key autoincrement not null, "position" varchar not null default 'home_top', "title" varchar, "subtitle" varchar, "link" varchar, "link_text" varchar, "image_id" integer, "target" integer not null default '0', "sort" integer not null default '0', "is_active" tinyint(1) not null default '1', "start_at" datetime, "end_at" datetime, "created_at" datetime, "updated_at" datetime);

-- INDEX: banners_position_is_active_sort_index
CREATE INDEX "banners_position_is_active_sort_index" on "banners" ("position", "is_active", "sort");

-- ===== cache (rows: 8) =====
CREATE TABLE "cache" ("key" varchar not null, "value" text not null, "expiration" integer not null, primary key ("key"));

-- ===== cache_locks (rows: 0) =====
CREATE TABLE "cache_locks" ("key" varchar not null, "owner" varchar not null, "expiration" integer not null, primary key ("key"));

-- ===== categories (rows: 14) =====
CREATE TABLE "categories" ("id" integer primary key autoincrement not null, "parent_id" integer, "name" varchar not null, "slug" varchar not null, "type" varchar not null default 'list', "template" varchar, "description" text, "icon" varchar, "sort" integer not null default '0', "is_nav" tinyint(1) not null default '1', "is_active" tinyint(1) not null default '1', "seo_title" varchar, "seo_desc" varchar, "created_at" datetime, "updated_at" datetime);

-- INDEX: categories_is_nav_is_active_index
CREATE INDEX "categories_is_nav_is_active_index" on "categories" ("is_nav", "is_active");
-- INDEX: categories_parent_id_sort_index
CREATE INDEX "categories_parent_id_sort_index" on "categories" ("parent_id", "sort");
-- INDEX: categories_slug_unique
CREATE UNIQUE INDEX "categories_slug_unique" on "categories" ("slug");

-- ===== content_revisions (rows: 0) =====
CREATE TABLE "content_revisions" ("id" integer primary key autoincrement not null, "content_id" integer not null, "user_id" integer, "note" varchar, "snapshot" text not null, "created_at" datetime, "updated_at" datetime);

-- INDEX: content_revisions_content_id_created_at_index
CREATE INDEX "content_revisions_content_id_created_at_index" on "content_revisions" ("content_id", "created_at");

-- ===== contents (rows: 13) =====
CREATE TABLE "contents" ("id" integer primary key autoincrement not null, "type" varchar not null default 'article', "category_id" integer, "group_id" integer, "title" varchar not null, "slug" varchar not null, "summary" text, "cover_id" integer, "body" text, "status" varchar not null default 'draft', "published_at" datetime, "geo_conclusion" text, "geo_explanation" text, "geo_evidence" text, "geo_boundary" text, "geo_faq" text, "geo_key_facts" text, "fact_refs" text, "owner" varchar, "reviewed_at" date, "review_due" date, "source_note" varchar, "seo_title" varchar, "seo_desc" varchar, "canonical" varchar, "og_image_id" integer, "noindex" tinyint(1) not null default '0', "external_id" varchar, "external_source" varchar, "synced_at" datetime, "content_hash" varchar, "created_at" datetime, "updated_at" datetime, "deleted_at" datetime, "lock_manual" tinyint(1) not null default '0', "slot" varchar);

-- INDEX: contents_category_id_group_id_index
CREATE INDEX "contents_category_id_group_id_index" on "contents" ("category_id", "group_id");
-- INDEX: contents_external_id_index
CREATE INDEX "contents_external_id_index" on "contents" ("external_id");
-- INDEX: contents_slot_unique
CREATE UNIQUE INDEX "contents_slot_unique" on "contents" ("slot");
-- INDEX: contents_slug_unique
CREATE UNIQUE INDEX "contents_slug_unique" on "contents" ("slug");
-- INDEX: contents_status_published_at_index
CREATE INDEX "contents_status_published_at_index" on "contents" ("status", "published_at");
-- INDEX: contents_type_category_id_index
CREATE INDEX "contents_type_category_id_index" on "contents" ("type", "category_id");

-- ===== facts (rows: 23) =====
CREATE TABLE "facts" ("id" integer primary key autoincrement not null, "key" varchar not null, "label" varchar not null, "value" text not null, "group" varchar, "source" varchar, "owner" varchar, "reviewed_at" date, "review_due" date, "is_public" tinyint(1) not null default '1', "sort" integer not null default '0', "created_at" datetime, "updated_at" datetime);

-- INDEX: facts_group_sort_index
CREATE INDEX "facts_group_sort_index" on "facts" ("group", "sort");
-- INDEX: facts_key_unique
CREATE UNIQUE INDEX "facts_key_unique" on "facts" ("key");
-- INDEX: facts_review_due_index
CREATE INDEX "facts_review_due_index" on "facts" ("review_due");

-- ===== failed_jobs (rows: 0) =====
CREATE TABLE "failed_jobs" ("id" integer primary key autoincrement not null, "uuid" varchar not null, "connection" text not null, "queue" text not null, "payload" text not null, "exception" text not null, "failed_at" datetime not null default CURRENT_TIMESTAMP);

-- INDEX: failed_jobs_uuid_unique
CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs" ("uuid");

-- ===== groups (rows: 9) =====
CREATE TABLE "groups" ("id" integer primary key autoincrement not null, "category_id" integer not null, "name" varchar not null, "slug" varchar not null, "description" text, "sort" integer not null default '0', "is_active" tinyint(1) not null default '1', "created_at" datetime, "updated_at" datetime, foreign key("category_id") references "categories"("id") on delete cascade);

-- INDEX: groups_category_id_slug_unique
CREATE UNIQUE INDEX "groups_category_id_slug_unique" on "groups" ("category_id", "slug");
-- INDEX: groups_category_id_sort_index
CREATE INDEX "groups_category_id_sort_index" on "groups" ("category_id", "sort");

-- ===== inquiries (rows: 0) =====
CREATE TABLE "inquiries" ("id" integer primary key autoincrement not null, "name" varchar not null, "phone" varchar not null, "company" varchar, "demand_type" varchar not null default '其他', "monthly_use" varchar, "message" text not null, "source_page" varchar, "ip" varchar, "user_agent" varchar, "status" varchar not null default 'new', "handle_note" text, "handled_at" datetime, "created_at" datetime, "updated_at" datetime, "landing_url" varchar, "referer" varchar, "utm_source" varchar, "utm_medium" varchar, "utm_campaign" varchar, "utm_term" varchar, "utm_content" varchar, "device_type" varchar);

-- INDEX: inquiries_status_created_at_index
CREATE INDEX "inquiries_status_created_at_index" on "inquiries" ("status", "created_at");

-- ===== job_batches (rows: 0) =====
CREATE TABLE "job_batches" ("id" varchar not null, "name" varchar not null, "total_jobs" integer not null, "pending_jobs" integer not null, "failed_jobs" integer not null, "failed_job_ids" text not null, "options" text, "cancelled_at" integer, "created_at" integer not null, "finished_at" integer, primary key ("id"));

-- ===== jobs (rows: 0) =====
CREATE TABLE "jobs" ("id" integer primary key autoincrement not null, "queue" varchar not null, "payload" text not null, "attempts" integer not null, "reserved_at" integer, "available_at" integer not null, "created_at" integer not null);

-- INDEX: jobs_queue_index
CREATE INDEX "jobs_queue_index" on "jobs" ("queue");

-- ===== media (rows: 12) =====
CREATE TABLE "media" ("id" integer primary key autoincrement not null, "disk" varchar not null default 'public', "path" varchar not null, "original_name" varchar, "mime" varchar, "size" integer not null default '0', "width" integer, "height" integer, "alt" varchar, "title" varchar, "uploaded_by" integer, "created_at" datetime, "updated_at" datetime);

-- INDEX: media_mime_index
CREATE INDEX "media_mime_index" on "media" ("mime");

-- ===== menus (rows: 1) =====
CREATE TABLE "menus" ("id" integer primary key autoincrement not null, "position" varchar not null default 'main', "parent_id" integer, "label" varchar not null, "url" varchar, "category_id" integer, "target" integer not null default '0', "sort" integer not null default '0', "is_active" tinyint(1) not null default '1', "created_at" datetime, "updated_at" datetime, "key" varchar, "parent_key" varchar);

-- INDEX: menus_key_unique
CREATE UNIQUE INDEX "menus_key_unique" on "menus" ("key");
-- INDEX: menus_parent_key_is_active_index
CREATE INDEX "menus_parent_key_is_active_index" on "menus" ("parent_key", "is_active");
-- INDEX: menus_position_is_active_sort_index
CREATE INDEX "menus_position_is_active_sort_index" on "menus" ("position", "is_active", "sort");

-- ===== migrations (rows: 26) =====
CREATE TABLE "migrations" ("id" integer primary key autoincrement not null, "migration" varchar not null, "batch" integer not null);

-- ===== page_blocks (rows: 16) =====
CREATE TABLE "page_blocks" ("id" integer primary key autoincrement not null, "page" varchar not null default 'home', "type" varchar not null, "title" varchar, "subtitle" varchar, "content" text, "category_id" integer, "limit" integer not null default '6', "sort" integer not null default '0', "is_active" tinyint(1) not null default '1', "created_at" datetime, "updated_at" datetime);

-- INDEX: page_blocks_page_is_active_sort_index
CREATE INDEX "page_blocks_page_is_active_sort_index" on "page_blocks" ("page", "is_active", "sort");

-- ===== password_reset_tokens (rows: 0) =====
CREATE TABLE "password_reset_tokens" ("email" varchar not null, "token" varchar not null, "created_at" datetime, primary key ("email"));

-- ===== redirects (rows: 0) =====
CREATE TABLE "redirects" ("id" integer primary key autoincrement not null, "from_path" varchar not null, "to_path" varchar not null, "code" integer not null default '301', "hits" integer not null default '0', "is_active" tinyint(1) not null default '1', "created_at" datetime, "updated_at" datetime);

-- INDEX: redirects_from_path_unique
CREATE UNIQUE INDEX "redirects_from_path_unique" on "redirects" ("from_path");

-- ===== sessions (rows: 5) =====
CREATE TABLE "sessions" ("id" varchar not null, "user_id" integer, "ip_address" varchar, "user_agent" text, "payload" text not null, "last_activity" integer not null, primary key ("id"));

-- INDEX: sessions_last_activity_index
CREATE INDEX "sessions_last_activity_index" on "sessions" ("last_activity");
-- INDEX: sessions_user_id_index
CREATE INDEX "sessions_user_id_index" on "sessions" ("user_id");

-- ===== settings (rows: 67) =====
CREATE TABLE "settings" ("id" integer primary key autoincrement not null, "key" varchar not null, "value" text, "group" varchar not null default 'general', "label" varchar, "type" varchar not null default 'text', "hint" text, "sort" integer not null default '0', "created_at" datetime, "updated_at" datetime);

-- INDEX: settings_group_sort_index
CREATE INDEX "settings_group_sort_index" on "settings" ("group", "sort");
-- INDEX: settings_key_unique
CREATE UNIQUE INDEX "settings_key_unique" on "settings" ("key");

-- ===== sync_logs (rows: 0) =====
CREATE TABLE "sync_logs" ("id" integer primary key autoincrement not null, "direction" varchar not null, "action" varchar, "external_id" varchar, "content_id" integer, "status" varchar not null default 'ok', "message" text, "payload" text, "created_at" datetime, "updated_at" datetime);

-- INDEX: sync_logs_created_at_index
CREATE INDEX "sync_logs_created_at_index" on "sync_logs" ("created_at");
-- INDEX: sync_logs_direction_status_index
CREATE INDEX "sync_logs_direction_status_index" on "sync_logs" ("direction", "status");
-- INDEX: sync_logs_external_id_index
CREATE INDEX "sync_logs_external_id_index" on "sync_logs" ("external_id");

-- ===== users (rows: 1) =====
CREATE TABLE "users" ("id" integer primary key autoincrement not null, "name" varchar not null, "email" varchar not null, "email_verified_at" datetime, "password" varchar not null, "remember_token" varchar, "created_at" datetime, "updated_at" datetime);

-- INDEX: users_email_unique
CREATE UNIQUE INDEX "users_email_unique" on "users" ("email");

-- Total rows across all tables: 224
