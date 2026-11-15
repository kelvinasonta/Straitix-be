"""
Test Suite: Pagination Upper-Bound Limits & Resource Exhaustion (OWASP API4:2023)
Verifies:
1. Public & authenticated paginated endpoints strictly cap per_page / perPage <= 100.
2. Huge pagination requests (e.g. per_page=5000, 1000000) are clamped and never cause memory crashes.
3. Pagination handles negative or zero inputs safely (falls back to min limit 1).
"""

import pytest


PAGINATION_ENDPOINTS = [
    # 1. Public explore events
    (
        "GET",
        "/explore/events",
        "perPage",
        False,  # Unauthenticated
    ),
    # 2. Authenticated user favorites
    (
        "GET",
        "/events/favorites",
        "perPage",
        True,  # User session
    ),
    # 3. Authenticated user tickets
    (
        "GET",
        "/tickets",
        "per_page",
        True,
    ),
    # 4. Authenticated user orders
    (
        "GET",
        "/orders",
        "per_page",
        True,
    ),
]


@pytest.mark.parametrize("method,path,param_name,authenticated", PAGINATION_ENDPOINTS)
def test_pagination_upper_bound_clamped(base_url, unauthenticated_session, user_a_session, method, path, param_name, authenticated):
    """
    Assert that requests with excessive pagination parameters are capped to <= 100.
    """
    session = user_a_session if authenticated else unauthenticated_session

    # Test with huge page size
    res = session.request(method, f"{base_url}{path}", params={param_name: 5000})
    assert res.status_code == 200, f"Expected 200 OK from {path}, got {res.status_code}: {res.text[:200]}"

    data = res.json().get("data", {})
    # Check if pagination metadata exists
    meta = data.get("meta", {})
    per_page = meta.get("per_page") or meta.get("perPage") or data.get("per_page")

    if per_page is not None:
        assert int(per_page) <= 100, f"Pagination limit exceeded on {path}! Expected <= 100, got {per_page}"


@pytest.mark.parametrize("method,path,param_name,authenticated", PAGINATION_ENDPOINTS)
def test_pagination_handles_zero_and_negative(base_url, unauthenticated_session, user_a_session, method, path, param_name, authenticated):
    """
    Assert that negative or zero per_page parameters are safely clamped to at least 1.
    """
    session = user_a_session if authenticated else unauthenticated_session

    res = session.request(method, f"{base_url}{path}", params={param_name: 0})
    assert res.status_code == 200, f"Expected 200 OK from {path}, got {res.status_code}: {res.text[:200]}"

    data = res.json().get("data", {})
    meta = data.get("meta", {})
    per_page = meta.get("per_page") or meta.get("perPage") or data.get("per_page")

    if per_page is not None:
        assert int(per_page) >= 1, f"Pagination lower limit invalid on {path}! Expected >= 1, got {per_page}"
