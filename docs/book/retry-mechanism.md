# Retry Mechanism

The **Maatify Email Delivery** library utilizes a robust worker process that inherently handles transient failures and ensures reliable delivery through a built-in exponential backoff mechanism.

## Exponential Backoff Logic

When the `EmailQueueWorker` attempts to process a pending email job, numerous issues can occur. The database might be temporarily unavailable, the Twig renderer might encounter an error, or the SMTP server might reject the connection. Instead of immediately failing the job or continuously retrying it—which could overwhelm the system or trigger rate limits—the worker employs an intelligent retry strategy.

The library implements this retry logic through an exponential backoff algorithm. This means the delay between retry attempts increases with each subsequent failure.

The worker tracks the number of times it has tried to process a specific job using the `attempts` column in the `cd_email_queue` database table.

The backoff duration is determined by two predefined arrays of delays (in seconds):

1.  **Default Backoff (`BACKOFF_DEFAULT`)**: Used for errors like template rendering failures or cryptography issues. These typically require human intervention to fix (e.g., correcting a typo in a template or restoring a missing encryption key), so the delays are longer:
    -   Attempt 1: 60 seconds
    -   Attempt 2: 300 seconds (5 minutes)
    -   Attempt 3: 900 seconds (15 minutes)

2.  **SMTP Backoff (`BACKOFF_SMTP`)**: Used specifically for `smtp_transport_error` exceptions. SMTP issues are often transient network glitches or temporary server unresponsiveness that resolve quickly. The delays here are significantly shorter:
    -   Attempt 1: 30 seconds
    -   Attempt 2: 60 seconds
    -   Attempt 3: 120 seconds (2 minutes)

When an error occurs, the worker:

1.  Logs the failure with the relevant error code (`job_id`, `attempt`, `error_code`, `reason`).
2.  Determines the appropriate backoff array based on the error code.
3.  Checks if the current `attempts` count has reached the maximum allowed (defined as `MAX_ATTEMPTS = 4`).

If the maximum attempts are reached, the worker marks the job's status as `failed` permanently, recording the error in the `last_error` column. It logs a warning that the job has permanently failed.

If the maximum attempts have not been reached, the worker updates the `status` back to `pending`, sets the `last_error`, and calculates the `retry_after` timestamp by adding the delay from the backoff array to the current time. It logs that the job is scheduled for a retry.

This mechanism ensures that transient errors are handled gracefully and that persistent issues eventually fail cleanly without indefinitely looping or flooding the logs.
