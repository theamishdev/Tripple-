<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PolicyFlow | Insurance Issuance & Reconciliation Engine</title>
    <style>
        :root {
            --bg-page: #f8fafc;
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --primary: #0284c7;
            --primary-hover: #0369a1;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-family);
            background-color: var(--bg-page);
            color: var(--text-main);
            line-height: 1.5;
            padding: 24px 16px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        header {
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 16px;
        }

        .logo-area h1 {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.5px;
            color: var(--text-main);
        }

        .logo-area p {
            font-size: 13px;
            color: var(--text-muted);
        }

        .system-badge {
            background: #e0f2fe;
            color: #0369a1;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 14px 18px;
        }

        .stat-card .label {
            font-size: 12px;
            text-transform: uppercase;
            font-weight: 600;
            color: var(--text-muted);
        }

        .stat-card .value {
            font-size: 24px;
            font-weight: 700;
            margin-top: 4px;
        }

        /* Two columns layout for Intake and Listing */
        .main-grid {
            display: grid;
            grid-template-columns: 380px 1fr;
            gap: 24px;
        }

        @media (max-width: 900px) {
            .main-grid {
                grid-template-columns: 1fr;
            }
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        }

        .card-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Forms */
        .form-group {
            margin-bottom: 12px;
        }

        .form-row {
            display: flex;
            gap: 12px;
        }

        .form-row .form-group {
            flex: 1;
        }

        label {
            display: block;
            font-size: 12px;
            font-weight: 500;
            color: #334155;
            margin-bottom: 4px;
        }

        input, select {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 13px;
            color: var(--text-main);
            background: #fff;
            transition: border-color 0.15s ease;
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--primary);
        }

        .quick-fill-btn {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
            color: #475569;
        }

        .quick-fill-btn:hover {
            background: #e2e8f0;
        }

        .btn-primary {
            display: inline-block;
            width: 100%;
            background: var(--primary);
            color: white;
            border: none;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 8px;
            transition: background 0.15s ease;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        /* Table */
        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            text-align: left;
        }

        th {
            background: #f8fafc;
            color: var(--text-muted);
            font-weight: 600;
            padding: 10px 12px;
            border-bottom: 1px solid var(--border-color);
            font-size: 11px;
            text-transform: uppercase;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }

        tr:hover {
            background: #f8fafc;
        }

        /* Status Badges */
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        .badge-RECEIVED { background: #f1f5f9; color: #475569; }
        .badge-VALIDATED { background: #e0f2fe; color: #0284c7; }
        .badge-SUBMITTED { background: #e0e7ff; color: #4338ca; }
        .badge-PENDING_CONFIRMATION { background: #fef3c7; color: #b45309; }
        .badge-ISSUED { background: #dcfce7; color: #15803d; }
        .badge-FAILED { background: #fee2e2; color: #b91c1c; }
        .badge-NEEDS_RECONCILIATION { background: #ffedd5; color: #c2410c; }

        /* Action Buttons in Table */
        .btn-action {
            padding: 5px 9px;
            font-size: 12px;
            border-radius: 4px;
            border: 1px solid var(--border-color);
            background: #ffffff;
            cursor: pointer;
            margin-right: 4px;
            font-weight: 500;
        }

        .btn-action:hover {
            background: #f1f5f9;
        }

        .btn-submit-action {
            background: #0284c7;
            color: #fff;
            border-color: #0284c7;
        }
        .btn-submit-action:hover {
            background: #0369a1;
        }

        .btn-reconcile-action {
            background: #ea580c;
            color: #fff;
            border-color: #ea580c;
        }
        .btn-reconcile-action:hover {
            background: #c2410c;
        }

        /* Modal / Detail Drawer */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.4);
            backdrop-filter: blur(2px);
            z-index: 100;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-card {
            background: white;
            border-radius: 8px;
            width: 100%;
            max-width: 650px;
            max-height: 85vh;
            overflow-y: auto;
            padding: 24px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 10px;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: var(--text-muted);
        }

        .timeline {
            border-left: 2px solid #e2e8f0;
            margin-left: 10px;
            padding-left: 16px;
        }

        .timeline-item {
            margin-bottom: 16px;
            position: relative;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -22px;
            top: 4px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--primary);
            border: 2px solid white;
        }

        .timeline-time {
            font-size: 11px;
            color: var(--text-muted);
        }

        .timeline-title {
            font-size: 13px;
            font-weight: 600;
        }

        .timeline-desc {
            font-size: 12px;
            color: #475569;
            margin-top: 2px;
        }

        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #0f172a;
            color: white;
            padding: 12px 18px;
            border-radius: 6px;
            font-size: 13px;
            display: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 200;
        }
    </style>
</head>
<body>

<div class="container">
    <header>
        <div class="logo-area">
            <h1>POLICYFLOW</h1>
            <p>Fault-Tolerant Insurance Issuance & Idempotent Reconciliation Engine</p>
        </div>
        <div>
            <span class="system-badge">API Online • Idempotency Active</span>
        </div>
    </header>

    <!-- Stats Bar -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Total Requests</div>
            <div class="value" id="stat-total">0</div>
        </div>
        <div class="stat-card">
            <div class="label">Issued Policies</div>
            <div class="value" id="stat-issued" style="color: #16a34a;">0</div>
        </div>
        <div class="stat-card">
            <div class="label">Needs Reconciliation</div>
            <div class="value" id="stat-recon" style="color: #ea580c;">0</div>
        </div>
        <div class="stat-card">
            <div class="label">Failed / Retriable</div>
            <div class="value" id="stat-failed" style="color: #dc2626;">0</div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-grid">
        <!-- Policy Intake Form -->
        <div class="card">
            <div class="card-title">
                <span>New Policy Request</span>
                <button type="button" class="quick-fill-btn" onclick="fillExample()">Fill Assessment Example</button>
            </div>

            <form id="policy-form" onsubmit="handleFormSubmit(event)">
                <div class="form-row">
                    <div class="form-group">
                        <label>Client Request ID</label>
                        <input type="text" id="client_request_id" required value="AGT-2026-0001">
                    </div>
                    <div class="form-group">
                        <label>Agent ID</label>
                        <input type="text" id="agent_id" required value="AGENT-17">
                    </div>
                </div>

                <div class="form-group">
                    <label>Customer Name</label>
                    <input type="text" id="customer_name" required value="Sample Customer">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Date of Birth</label>
                        <input type="date" id="date_of_birth" required value="1998-04-10">
                    </div>
                    <div class="form-group">
                        <label>Destination</label>
                        <select id="destination" required>
                            <option value="INTERNATIONAL" selected>INTERNATIONAL (1.80x)</option>
                            <option value="DOMESTIC">DOMESTIC (1.00x)</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Trip Start</label>
                        <input type="date" id="trip_start" required value="2026-11-10">
                    </div>
                    <div class="form-group">
                        <label>Trip End</label>
                        <input type="date" id="trip_end" required value="2026-11-14">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Plan Code</label>
                        <select id="plan_code" required>
                            <option value="BASIC">BASIC (₹12/day)</option>
                            <option value="PLUS" selected>PLUS (₹20/day)</option>
                            <option value="PREMIUM">PREMIUM (₹30/day)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Sum Insured</label>
                        <select id="sum_insured" required>
                            <option value="100000">₹100,000 (1.00x)</option>
                            <option value="300000" selected>₹300,000 (1.25x)</option>
                            <option value="500000">₹500,000 (1.50x)</option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn-primary" id="submit-btn">Validate & Create Request</button>
            </form>
        </div>

        <!-- Policy Requests Table -->
        <div class="card">
            <div class="card-title">
                <span>Recent Policy Requests</span>
                <div>
                    <select id="status-filter" style="width: auto; padding: 4px 8px; font-size: 12px;" onchange="loadPolicies()">
                        <option value="">All Statuses</option>
                        <option value="VALIDATED">VALIDATED</option>
                        <option value="ISSUED">ISSUED</option>
                        <option value="NEEDS_RECONCILIATION">NEEDS RECONCILIATION</option>
                        <option value="PENDING_CONFIRMATION">PENDING</option>
                        <option value="FAILED">FAILED</option>
                    </select>
                </div>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Ref / Agent</th>
                            <th>Customer & Trip</th>
                            <th>Plan / Premium</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="policies-tbody">
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">Loading policies...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Audit Trail & Details Modal -->
<div class="modal-overlay" id="detail-modal">
    <div class="modal-card">
        <div class="modal-header">
            <h3 id="modal-title">Policy Audit Trail</h3>
            <button class="close-btn" onclick="closeModal()">&times;</button>
        </div>
        <div id="modal-content">
            Loading details...
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
    let currentPolicies = [];

    function showToast(message) {
        const toast = document.getElementById('toast');
        toast.innerText = message;
        toast.style.display = 'block';
        setTimeout(() => {
            toast.style.display = 'none';
        }, 3500);
    }

    function fillExample() {
        const randomNum = Math.floor(1000 + Math.random() * 9000);
        document.getElementById('client_request_id').value = 'AGT-2026-' + randomNum;
        document.getElementById('agent_id').value = 'AGENT-17';
        document.getElementById('customer_name').value = 'Sample Customer';
        document.getElementById('date_of_birth').value = '1998-04-10';
        document.getElementById('destination').value = 'INTERNATIONAL';
        document.getElementById('trip_start').value = '2026-11-10';
        document.getElementById('trip_end').value = '2026-11-14';
        document.getElementById('plan_code').value = 'PLUS';
        document.getElementById('sum_insured').value = '300000';
    }

    async function loadPolicies() {
        const status = document.getElementById('status-filter').value;
        const url = status ? `/api/policy-requests?status=${status}` : '/api/policy-requests';
        try {
            const res = await fetch(url);
            const data = await res.json();
            if (data.success) {
                currentPolicies = data.data;
                renderTable(data.data);
                updateStats(data.data);
            }
        } catch (e) {
            console.error(e);
        }
    }

    function updateStats(policies) {
        document.getElementById('stat-total').innerText = policies.length;
        document.getElementById('stat-issued').innerText = policies.filter(p => p.status === 'ISSUED').length;
        document.getElementById('stat-recon').innerText = policies.filter(p => p.status === 'NEEDS_RECONCILIATION').length;
        document.getElementById('stat-failed').innerText = policies.filter(p => p.status === 'FAILED').length;
    }

    function renderTable(policies) {
        const tbody = document.getElementById('policies-tbody');
        if (!policies.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 24px;">No policy requests found. Create one using the form on the left.</td></tr>';
            return;
        }

        tbody.innerHTML = policies.map(p => `
            <tr>
                <td>
                    <strong>${p.client_request_id}</strong><br>
                    <span style="font-size: 11px; color: var(--text-muted);">${p.agent_id}</span>
                </td>
                <td>
                    ${p.customer_name}<br>
                    <span style="font-size: 11px; color: var(--text-muted);">${p.destination} (${p.travel_days} days)</span>
                </td>
                <td>
                    <strong>₹${Number(p.premium_amount).toFixed(2)}</strong><br>
                    <span style="font-size: 11px; color: var(--text-muted);">${p.plan_code} • ₹${Number(p.sum_insured).toLocaleString()}</span>
                </td>
                <td>
                    <span class="badge badge-${p.status}">${p.status}</span>
                    ${p.policy_number ? `<br><span style="font-size: 10px; font-family: monospace; color: #047857;">${p.policy_number}</span>` : ''}
                </td>
                <td>
                    ${renderActions(p)}
                    <button class="btn-action" onclick="viewAuditTrail(${p.id})">Audit</button>
                </td>
            </tr>
        `).join('');
    }

    function renderActions(p) {
        if (p.status === 'VALIDATED' || p.status === 'FAILED') {
            return `
                <select id="scenario-${p.id}" style="width: auto; padding: 4px; font-size: 11px; margin-right: 4px;">
                    <option value="SUCCESS">Success</option>
                    <option value="TIMEOUT_AFTER_ISSUE">Timeout Scenario</option>
                    <option value="TEMPORARY_FAILURE">503 Temp Failure</option>
                    <option value="DELAYED_CALLBACK">Delayed Callback</option>
                </select>
                <button class="btn-action btn-submit-action" onclick="submitToInsurer(${p.id})">Submit</button>
            `;
        } else if (p.status === 'NEEDS_RECONCILIATION') {
            return `<button class="btn-action btn-reconcile-action" onclick="reconcilePolicy(${p.id})">Reconcile</button>`;
        } else if (p.status === 'PENDING_CONFIRMATION') {
            return `<button class="btn-action" style="background: #f59e0b; color: white;" onclick="triggerCallback('${p.provider_reference}')">Send Callback</button>`;
        }
        return '';
    }

    async function handleFormSubmit(e) {
        e.preventDefault();
        const payload = {
            client_request_id: document.getElementById('client_request_id').value,
            agent_id: document.getElementById('agent_id').value,
            customer_name: document.getElementById('customer_name').value,
            date_of_birth: document.getElementById('date_of_birth').value,
            destination: document.getElementById('destination').value,
            trip_start: document.getElementById('trip_start').value,
            trip_end: document.getElementById('trip_end').value,
            plan_code: document.getElementById('plan_code').value,
            sum_insured: Number(document.getElementById('sum_insured').value),
        };

        try {
            const res = await fetch('/api/policy-requests', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (res.ok) {
                showToast(data.is_duplicate ? 'Notice: Existing policy safely returned (Idempotent)' : 'Policy Request Created & Validated!');
                loadPolicies();
            } else {
                alert('Validation error: ' + (data.message || JSON.stringify(data.errors)));
            }
        } catch (e) {
            console.error(e);
            alert('Error creating policy request.');
        }
    }

    async function submitToInsurer(id) {
        const scenario = document.getElementById(`scenario-${id}`)?.value || 'SUCCESS';
        try {
            const res = await fetch(`/api/policy-requests/${id}/submit`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Mock-Scenario': scenario },
                body: JSON.stringify({ mock_scenario: scenario })
            });
            const data = await res.json();
            showToast(data.message || 'Issuance action completed');
            loadPolicies();
        } catch (e) {
            console.error(e);
        }
    }

    async function reconcilePolicy(id) {
        try {
            const res = await fetch(`/api/policy-requests/${id}/reconcile`, {
                method: 'POST',
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();
            showToast(data.message || 'Reconciliation completed');
            loadPolicies();
        } catch (e) {
            console.error(e);
        }
    }

    async function triggerCallback(providerRef) {
        try {
            const res = await fetch('/api/mock-insurer/trigger-callback', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ provider_reference: providerRef })
            });
            const data = await res.json();
            showToast('Simulated insurer callback received and processed!');
            loadPolicies();
        } catch (e) {
            console.error(e);
        }
    }

    async function viewAuditTrail(id) {
        try {
            const res = await fetch(`/api/policy-requests/${id}`);
            const data = await res.json();
            if (data.success) {
                const p = data.data;
                document.getElementById('modal-title').innerText = `Audit Trail: ${p.client_request_id}`;
                
                let html = `
                    <div style="background: #f8fafc; padding: 12px; border-radius: 6px; margin-bottom: 16px; font-size: 13px;">
                        <div><strong>Policy Number:</strong> ${p.policy_number || 'N/A (Not Issued)'}</div>
                        <div><strong>Status:</strong> <span class="badge badge-${p.status}">${p.status}</span></div>
                        <div><strong>Provider Reference:</strong> ${p.provider_reference || 'N/A'}</div>
                        <div><strong>Attempts:</strong> ${p.attempt_count}</div>
                        <div><strong>Premium:</strong> ₹${Number(p.premium_amount).toFixed(2)} (Immutable snapshot)</div>
                    </div>
                    <h4 style="font-size: 14px; margin-bottom: 12px;">State History</h4>
                    <div class="timeline">
                `;

                (p.state_histories || []).forEach(h => {
                    html += `
                        <div class="timeline-item">
                            <div class="timeline-time">${new Date(h.created_at).toLocaleString()}</div>
                            <div class="timeline-title">${h.from_state || 'START'} &rarr; ${h.to_state}</div>
                            <div class="timeline-desc">${h.reason}</div>
                        </div>
                    `;
                });

                html += '</div>';

                if (p.attempts && p.attempts.length > 0) {
                    html += `
                        <h4 style="font-size: 14px; margin-top: 16px; margin-bottom: 8px;">Issuance Attempts Log</h4>
                        <table style="font-size: 12px;">
                            <thead>
                                <tr><th>#</th><th>Scenario</th><th>Status</th><th>Latency</th><th>Error</th></tr>
                            </thead>
                            <tbody>
                                ${p.attempts.map(a => `
                                    <tr>
                                        <td>${a.attempt_number}</td>
                                        <td>${a.scenario || 'SUCCESS'}</td>
                                        <td><span class="badge badge-${a.status}">${a.status}</span></td>
                                        <td>${a.latency_ms || 0}ms</td>
                                        <td style="color: #dc2626;">${a.error_message || '-'}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    `;
                }

                document.getElementById('modal-content').innerHTML = html;
                document.getElementById('detail-modal').classList.add('active');
            }
        } catch (e) {
            console.error(e);
        }
    }

    function closeModal() {
        document.getElementById('detail-modal').classList.remove('active');
    }

    // Initial load
    loadPolicies();
</script>
</body>
</html>
