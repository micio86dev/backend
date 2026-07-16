/**
 * K6 baseline scenario (D35).
 * 10 virtual users for 60 seconds against GET /api/health.
 *
 * Prerequisites:
 *   - docker compose up -d (full stack healthy)
 *   - k6 installed: brew install k6
 *
 * Run:
 *   k6 run tests/k6/scenarios/baseline.js
 *   k6 run --summary-export=docs/load-testing/baseline-report.json tests/k6/scenarios/baseline.js
 *
 * Target URL: K6_API_BASE_URL env var (default: http://localhost:8000)
 * NEVER run against Railway stage/prod — local Docker Compose stack only (D35).
 */

import http from 'k6/http'
import { check, sleep } from 'k6'
import { healthThresholds } from './thresholds.js'

const BASE_URL = __ENV.K6_API_BASE_URL || 'http://localhost:8000'

export const options = {
  vus: 10,
  duration: '60s',
  thresholds: healthThresholds,
}

export default function () {
  const res = http.get(`${BASE_URL}/api/health`)

  check(res, {
    'status is 200': (r) => r.status === 200,
    'body contains ok': (r) => r.json('status') === 'ok',
    'response time < 100ms': (r) => r.timings.duration < 100,
  })

  sleep(0.1)
}
