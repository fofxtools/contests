"""
Fit per-contest Luce ratings from data/board8wiki/match-records.json.

Strength model: phat_i = s_i / sum_j(s_j) over entrants j in the same poll,
s_i = exp(r_i), one free log-strength r_i per entrant per contest (one arbitrary
anchor entrant's r is fixed at 0 for identifiability). Fit by minimizing equal-poll
cross-entropy -- every poll weighted equally regardless of turnout:

    minimize  -sum_m sum_i p_mi * log(phat_mi)     where p_mi = v_mi / sum(v_m)

This is the "equal-poll cross-entropy" method selected after comparing it against
squared-share-error and vote-count-MLE fits (see local/b8w-xstats-methods.py and
local/output/board8wiki-xstats-methods/ for that comparison and its writeup).

Scope: official matches only (bonus matches excluded), battle royale included.
Fit is per contest -- ratings are not comparable across different contests.

Contests are ordered chronologically (by data/contest-ids.json's id), and each
contest's block carries that same "contest_id" plus its canonical display
name. contest_id itself just comes off each match record -- scripts/
board8wiki-records.py already resolved match-records.json's own "contest"
label to a contest_id via contest-ids.json's "codes" aliases (needed for
exactly two contests: "SpC2K4"/"SpC2K5" vs. contest-ids.json's "Spring 2K4"/
"Spring 2K5"), so there's no need to redo that join here.

Output: data/stats/luce-fit.json
    { contest: { "contest_id", "name", "start_date", "n_entrants", "n_polls",
                 "converged", "final_loss",
                 "entrants": [ {id, name, rating, rank}, ... ] } }
"""

import json

import numpy as np
from scipy.optimize import minimize

MATCH_RECORDS = "data/board8wiki/match-records.json"
CONTEST_IDS = "data/contest-ids.json"
OUTPUT = "data/stats/luce-fit.json"


def load_contest_names():
    """contest_id -> canonical display name, from contest-ids.json."""
    with open(CONTEST_IDS) as f:
        registry = json.load(f)

    return {c["id"]: c["name"] for c in registry}


def load_contest_matches():
    with open(MATCH_RECORDS) as f:
        records = json.load(f)

    by_contest = {}
    for m in records.values():
        if not m["official"]:
            continue
        by_contest.setdefault(m["contest"], []).append(m)
    return by_contest


def build_contest_arrays(matches):
    """
    Returns:
        ids: list of entrant ids, index 0 is the fixed anchor
        names: dict id -> name
        polls: list of (idx_array, p_array)
    """
    ids_seen = []
    id_set = set()
    names = {}
    for m in matches:
        for e in m["entrants"]:
            if e["id"] not in id_set:
                id_set.add(e["id"])
                ids_seen.append(e["id"])
                names[e["id"]] = e["name"]

    # Anchor = entrant appearing in the most matches (most-constrained, stable reference).
    appearance_count = {i: 0 for i in ids_seen}
    for m in matches:
        for e in m["entrants"]:
            appearance_count[e["id"]] += 1
    anchor = max(ids_seen, key=lambda i: (appearance_count[i], -i))
    ids = [anchor] + [i for i in ids_seen if i != anchor]
    index = {eid: k for k, eid in enumerate(ids)}

    polls = []
    for m in matches:
        idxs = np.array([index[e["id"]] for e in m["entrants"]], dtype=int)
        votes = np.array([e["votes"] for e in m["entrants"]], dtype=float)
        total = votes.sum()
        if total <= 0:
            continue
        p = votes / total
        polls.append((idxs, p))

    return ids, names, polls


def softmax_over_poll(r_full, idxs):
    z = r_full[idxs]
    z = z - z.max()
    e = np.exp(z)
    return e / e.sum()


def make_loss_and_grad(polls, n):
    """
    Equal-poll cross-entropy loss and gradient.
    r_full is length n with r_full[0] pinned to 0.
    Optimization variable x has length n-1 (r_full[1:]).
    """

    def loss_and_grad(x):
        r_full = np.empty(n)
        r_full[0] = 0.0
        r_full[1:] = x
        total_loss = 0.0
        grad_full = np.zeros(n)

        for idxs, p in polls:
            phat = softmax_over_poll(r_full, idxs)
            total_loss += float(-np.sum(p * np.log(np.clip(phat, 1e-300, None))))
            # standard softmax cross-entropy gradient: predicted - target
            g_local = phat - p
            np.add.at(grad_full, idxs, g_local)

        return total_loss, grad_full[1:]

    return loss_and_grad


def fit_contest(matches):
    ids, names, polls = build_contest_arrays(matches)
    n = len(ids)
    loss_and_grad = make_loss_and_grad(polls, n)

    x0 = np.zeros(n - 1)
    res = minimize(
        loss_and_grad,
        x0,
        jac=True,
        method="L-BFGS-B",
        options={"maxiter": 2000, "ftol": 1e-14, "gtol": 1e-10},
    )

    r_full = np.empty(n)
    r_full[0] = 0.0
    r_full[1:] = res.x
    s = np.exp(r_full - r_full.max())  # normalize so max strength = 1
    rating = 100.0 * s / (1.0 + s)

    order = np.argsort(-rating)
    rows = []
    for rank, k in enumerate(order, start=1):
        rows.append(
            {
                "id": int(ids[k]),
                "name": names[ids[k]],
                "rating": round(float(rating[k]), 2),
                "rank": rank,
            }
        )

    return {
        "n_entrants": n,
        "n_polls": len(polls),
        "converged": bool(res.success),
        "final_loss": float(res.fun),
        "entrants": rows,
    }


def main():
    by_contest = load_contest_matches()
    names_by_id = load_contest_names()

    # every match already carries its resolved contest_id (see this file's
    # header) -- just read it off, one group at a time, instead of re-deriving
    # it via contest-ids.json's aliases ourselves.
    contest_id_of = {}
    for label, matches in by_contest.items():
        ids = {m["contest_id"] for m in matches}
        assert len(ids) == 1, f"{label} has mixed contest_id values: {ids}"
        contest_id_of[label] = ids.pop()

    contests_chronological = sorted(by_contest, key=lambda c: contest_id_of[c])

    output = {}
    for contest in contests_chronological:
        matches = by_contest[contest]
        cid = contest_id_of[contest]
        start_date = min(m["date"] for m in matches)
        result = fit_contest(matches)
        result = {
            "contest_id": cid,
            "name": names_by_id[cid],
            "start_date": start_date,
            **result,
        }
        output[contest] = result
        print(
            f"[{cid:2d}] {contest}: {result['n_entrants']} entrants, {result['n_polls']} polls, "
            f"converged={result['converged']}, final_loss={result['final_loss']:.6f}"
        )

    with open(OUTPUT, "w") as f:
        json.dump(output, f, indent=2)
    print(f"\nWrote {OUTPUT}")


if __name__ == "__main__":
    main()
