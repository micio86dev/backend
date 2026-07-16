/**
 * K6 spike scenario (D35).
 * Ramp from 0 → 200 VU in 10 s, hold 30 s, ramp down.
 * Verifies error rate stays under 5% during a sudden traffic spike.
 *
 * Prerequisites:
 *   - docker compose up -d (full stack healthy)
 *   - k6 installed: brew install k6
 *
 * Run:
 *   k6 run tests/k6/scenarios/spike.js
 *   k6 run --summary-export=docs/load-testing/spike-report.json tests/k6/scenarios/spike.js
 *
 * Target URL: K6_API_BASE_URL env var (default: http://localhost:8000)
 * NEVER run against Railway stage/prod — local Docker Compose stack only (D35).
 */

import http from 'k6/http'
import { check } from 'k6'
import { healthSpikeThresholds } from './thresholds.js'

const BASE_URL = __ENV.K6_API_BASE_URL || 'http://localhost:8000'

export const options = {
  stages: [
    { duration: '10s', target: 200 }, // Rapid ramp up to 200 VU
    { duration: '30s', target: 200 }, // Hold spike load
    { duration: '10s', target: 0 }, // Ramp down
  ],
  thresholds: healthSpikeThresholds,
}

export default function () {
  const res = http.get(`${BASE_URL}/api/health`)

  check(res, {
    'status is 200': (r) => r.status === 200,
    'body contains ok': (r) => r.json('status') === 'ok',
  })
}
