<?php
// Agent API entry point: /api/agent/index.php?action=<name>
// Runs from modules/leads so its relative includes resolve as for the Hub pages.
// Implementation: modules/leads/agent_api.php — docs: docs/AGENT_API.md
chdir(__DIR__ . '/../../modules/leads');
require __DIR__ . '/../../modules/leads/agent_api.php';
