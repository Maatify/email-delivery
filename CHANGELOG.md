# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-03-11

### Added
- Queue system using PDO for durable email storage.
- Twig rendering support with automatic language injection.
- SMTP transport implementation backed by PHPMailer.
- Encryption support for queue payloads via `maatify/crypto`.
- Worker retry mechanism with exponential backoff for transient and permanent failures.
