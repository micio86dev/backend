/**
 * Shared K6 threshold definitions (D35).
 * Import this in each scenario script.
 *
 * Usage:
 *   import { healthThresholds } from './thresholds.js'
 *   export const options = { thresholds: healthThresholds }
 *
 * All thresholds assume the target is the local Docker Compose stack.
 * K6 load tests MUST NEVER run against Railway stage/prod (bandwidth cost).
 */

/** Health endpoint thresholds — baseline / stress scenarios */
export const healthThresholds = {
  // p95 response time under 100 ms for the health endpoint
  http_req_duration: ['p(95)<100'],
  // Error rate under 0.5%
  http_req_failed: ['rate<0.005'],
}

/** Health endpoint thresholds — stress scenario (more lenient) */
export const healthStressThresholds = {
  http_req_duration: ['p(95)<200'],
  http_req_failed: ['rate<0.01'],
}

/** Health endpoint thresholds — spike scenario (lenient — transient spike) */
export const healthSpikeThresholds = {
  // Spike: accept up to 5% error rate during the burst
  http_req_failed: ['rate<0.05'],
}

/**
 * Scoring endpoint thresholds (C8+, LLM-dependent — mocked during load tests).
 * Defined here so the full threshold set is documented; only used once
 * the scoring endpoint exists in C8.
 */
export const scoringThresholds = {
  http_req_duration: ['p(95)<10000'], // p95 < 10 s for scoring (async-heavy)
  http_req_failed: ['rate<0.01'],
}
