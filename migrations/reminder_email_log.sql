-- Migration: reminder_email_log
-- Tracks which reminder email notifications have already been sent
-- to prevent duplicate sends when the cron runs daily.

CREATE TABLE IF NOT EXISTS reminder_email_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    reminder_id INT NOT NULL,
    offset_days INT NOT NULL COMMENT '-7, -3, 0, or +3 days relative to alert_date',
    sent_date   DATE NOT NULL  COMMENT 'The calendar date on which the email was dispatched',
    sent_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_send (reminder_id, offset_days, sent_date),
    FOREIGN KEY (reminder_id) REFERENCES reminders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
