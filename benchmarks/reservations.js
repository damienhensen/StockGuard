import http from "k6/http";
import { check } from "k6";
import { Counter } from "k6/metrics";

const created = new Counter("reservations_created");
const replayed = new Counter("reservations_replayed");
const rejected = new Counter("reservations_rejected");
const unexpected = new Counter("unexpected_responses");

const mode = __ENV.MODE || "unique";
const baseUrl = __ENV.BASE_URL || "http://127.0.0.1:8080";
const productId = __ENV.PRODUCT_ID || "1";
const runId = __ENV.RUN_ID;

if (!["unique", "same"].includes(mode)) {
    throw new Error("MODE must be unique or same.");
}

if (!runId) {
    throw new Error("RUN_ID is required.");
}

export const options = {
    scenarios: {
        reservations: {
            executor: "per-vu-iterations",
            vus: 100,
            iterations: 1,
            maxDuration: "60s",
        },
    },
    thresholds: {
        checks: ["rate==1"],
        iterations: ["count==100"],
        http_reqs: ["count==100"],
        reservations_created: [mode === "unique" ? "count==10" : "count==1"],
        reservations_replayed: [mode === "same" ? "count==99" : "count==0"],
        reservations_rejected: [mode === "unique" ? "count==90" : "count==0"],
        unexpected_responses: ["count==0"],
    },
};

export default function () {
    // Ook nulwaarden registreren, zodat iedere metric bestaat.
    created.add(0);
    replayed.add(0);
    rejected.add(0);
    unexpected.add(0);

    const key = mode === "same" ? `${runId}-shared` : `${runId}-${__VU}`;

    const response = http.post(
        `${baseUrl}/api/products/${productId}/reservations`,
        JSON.stringify({ quantity: 1 }),
        {
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "Idempotency-Key": key,
            },
            timeout: "30s",
        },
    );

    let body = null;

    try {
        body = response.json();
    } catch {
        // Een ongeldige JSON-response telt hieronder als onverwacht.
    }

    const validReservation =
        Number.isInteger(body?.data?.reservation_id) &&
        body.data.reservation_id > 0 &&
        body.data.product_id === Number(productId) &&
        body.data.quantity === 1;

    let expected = false;

    if (
        response.status === 201 &&
        validReservation &&
        body.data.replayed === false
    ) {
        created.add(1);
        expected = true;
    } else if (
        mode === "same" &&
        response.status === 200 &&
        validReservation &&
        body.data.replayed === true
    ) {
        replayed.add(1);
        expected = true;
    } else if (
        mode === "unique" &&
        response.status === 409 &&
        body?.error?.code === "INSUFFICIENT_STOCK"
    ) {
        rejected.add(1);
        expected = true;
    } else {
        unexpected.add(1);
    }

    check(response, {
        "response matches reservation contract": () => expected,
    });
}
