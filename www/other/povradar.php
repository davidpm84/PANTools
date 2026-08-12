<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PoV Radar - TRR Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

    <style>
        :root {
            --primary-color: #00C55E; 
            --primary-hover: #00a34d;
            --bg-light: #F4F6F8;
            --sidebar-width: 280px;
        }
        
        body { 
            background-color: var(--bg-light); 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            display: flex;
            height: 100vh;
            overflow: hidden; 
        }

        /* --- SIDEBAR --- */
        .sidebar {
            width: var(--sidebar-width);
            background: white;
            border-right: 1px solid #ddd;
            display: flex;
            flex-direction: column;
            padding: 25px;
            flex-shrink: 0;
            z-index: 1000;
            overflow-y: auto;
        }

        .logo-container { margin-bottom: 20px; text-align: center; }
        .logo-container img { max-width: 180px; }

        /* User Badge */
        #userBadge {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 25px;
            text-align: center;
        }

        /* Stats */
        .stat-card {
            background: #fff;
            border: 1px solid #eee;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            border-left: 4px solid var(--primary-color);
        }
        .stat-label { font-size: 0.7rem; text-transform: uppercase; color: #6c757d; font-weight: 700; letter-spacing: 0.5px; }
        .stat-value { font-size: 1.3rem; font-weight: 800; color: #212529; margin-bottom: 0; }
        .stat-sub { font-size: 0.75rem; color: #adb5bd; }

        /* Links */
        .nav-link-sidebar {
            display: flex; align-items: center; color: #555; padding: 10px 15px;
            text-decoration: none; border-radius: 8px; transition: all 0.2s;
            margin-bottom: 5px; font-weight: 500; font-size: 0.95rem;
        }
        .nav-link-sidebar:hover { background-color: #f1f3f5; color: var(--primary-color); }
        .nav-link-sidebar i { width: 25px; text-align: center; margin-right: 10px; }
        .nav-link-sidebar.active { background-color: #e6fffa; color: var(--primary-color); font-weight: 700; }

        .btn-back { 
            margin-top: auto; 
            background: #343a40; color: white; border: none; font-weight: 600; padding: 12px; border-radius: 6px; text-decoration: none; text-align: center; display: block;
        }
        .btn-back:hover { background: #23272b; color: white; }

        /* --- MAIN WRAPPER --- */
        .main-wrapper {
            flex-grow: 1; display: flex; flex-direction: column; height: 100vh; overflow: hidden; background-color: var(--bg-light);
        }

        /* --- TOP NAVBAR --- */
        .top-navbar {
            background: white; border-bottom: 1px solid #eee; padding: 10px 25px;
            display: flex; align-items: center; justify-content: space-between;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02); flex-shrink: 0;
        }
        .nav-pills .nav-link { color: #555; font-weight: 600; border-radius: 6px; padding: 8px 16px; margin-right: 5px; cursor: pointer; }
        .nav-pills .nav-link.active { background-color: var(--primary-color); color: white; }
        
        /* --- FILTER BAR --- */
        .filter-bar { background: #fff; border-bottom: 1px solid #eee; padding: 12px 25px; flex-shrink: 0; }

        /* --- CONTENT AREA --- */
        .content-area { flex-grow: 1; overflow-y: auto; padding: 25px; }

        /* UI Elements */
        .btn-outline-purple { color: #6f42c1; border-color: #6f42c1; }
        .btn-outline-purple:hover { background-color: #6f42c1; color: white; }
        .btn-check:checked + .btn-outline-purple { background-color: #6f42c1; color: white; border-color: #6f42c1; }

        .btn-primary { background-color: var(--primary-color); border-color: var(--primary-color); font-weight: 600; }
        .btn-primary:hover { background-color: var(--primary-hover); border-color: var(--primary-hover); }

        .status-badge { padding: 4px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; display: inline-block; width: 100%; text-align: center;}
        .status-on-track { background-color: #d1e7dd; color: #0f5132; }
        .status-at-risk { background-color: #fff3cd; color: #664d03; }
        .status-planned { background-color: #cfe2ff; color: #084298; }
        .status-not-started { background-color: #e2e3e5; color: #41464b; }
        .status-parked { background-color: #d3d3d3; color: #555; border: 1px solid #999; }
        .status-closed { background-color: #212529; color: #fff; }

        .badge-opp { background-color: #cff4fc; color: #055160; border: 1px solid #b6effb; }
        .badge-post { background-color: #e0cffc; color: #3d0a91; border: 1px solid #d2bdfb; }
        .badge-event { background-color: #fff3cd; color: #664d03; border: 1px solid #ffecb5; }

        /* TABLE FIXES (Fixed Layout) */
        .table-fixed { table-layout: fixed; width: 100%; }
        .text-truncate-cell { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; }
        .amount-text { font-family: 'Consolas', monospace; font-weight: 700; color: #198754; font-size: 0.95rem; }

        /* Forecast */
        .forecast-cell { text-align: center; vertical-align: middle; padding: 10px !important; }
        .forecast-badge { display: inline-flex; align-items: center; justify-content: center; padding: 5px 10px; border-radius: 8px; font-weight: 600; font-size: 0.85rem; min-width: 110px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .legend-item { display: flex; align-items: center; gap: 8px; font-size: 0.8rem; color: #555; margin-right: 15px; }

        .card { border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border-radius: 8px; margin-bottom: 20px; }
        .view-section { display: none; }
        .view-section.active { display: block; animation: fadeIn 0.3s; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        /* Contenedor principal del logo */
.logo-container {
    display: flex;
    align-items: center;
    flex-shrink: 0; /* Evita que se deforme */
    padding: 5px 0;
}

/* ---- Estilos del ICONO SVG ---- */
.radar-icon {
    width: 42px;  /* Tamaño del icono */
    height: 42px;
    margin-right: 12px; /* Espacio entre icono y texto */
    
    /* COLORES DEL ICONO */
    /* Color base de los anillos (Gris tech) */
    --radar-base-color: #37474F; 
    /* Color de acento del barrido (Azul cian vibrante) */
    --radar-accent-color: #00C0F3; 
}

.radar-rings {
    stroke: var(--radar-base-color);
}

/* Grupo que contiene el punto central y el brazo que rotará */
.radar-sweep-group {
    color: var(--radar-accent-color); /* Define el color actual para fill/stroke */
    transform-origin: center;
    /* Animación suave de rotación continua */
    animation: radarSpin 4s linear infinite;
}

/* Definición de la animación de rotación */
@keyframes radarSpin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}


/* ---- Estilos del TEXTO ---- */
.logo-text {
    font-family: -apple-system, BlinkMacSystemFont, "Montserrat", "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    font-size: 24px;
    font-weight: 400;       /* Peso normal para "Radar" */
    color: #37474F;         /* Mismo gris oscuro que los anillos */
    letter-spacing: -0.5px;
    line-height: 1;
    user-select: none;
}

/* Estilo para la parte "PoV" */
.logo-text .highlight {
    font-weight: 800;       /* Extra negrita para énfasis */
    color: #00C0F3;         /* Mismo azul cian que el barrido del radar */
}
    </style>
</head>
<body>

<div class="sidebar">
    <div class="logo-container">
    <svg class="radar-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" aria-labelledby="radarTitle" role="img">
        <title id="radarTitle">PoV Radar Logo</title>
        <g class="radar-rings" fill="none" stroke-width="2" stroke-linecap="round">
            <circle cx="32" cy="32" r="28" opacity="0.6"></circle>
            <circle cx="32" cy="32" r="18" opacity="0.4"></circle>
        </g>
        
        <g class="radar-sweep-group">
            <circle class="radar-center" cx="32" cy="32" r="3.5"></circle>
            <path class="radar-beam" d="M32,32 L32,2 A30,30 0 0,1 58,17 L32,32" fill="currentColor" fill-opacity="0.2"></path>
            <line class="radar-arm" x1="32" y1="32" x2="58" y2="17" stroke="currentColor" stroke-width="3" stroke-linecap="round"></line>
        </g>
    </svg>
    <div class="logo-text">
        <span class="highlight">PoV</span>Radar
    </div>
</div>
    
    <div id="userBadge" style="display:none;">
        <small class="text-muted d-block text-uppercase" style="font-size: 0.65rem; letter-spacing: 1px;">Logged in as</small>
        <div class="fw-bold text-dark text-truncate" id="userNameDisplay" style="font-size: 0.9rem;"></div>
    </div>

    <div class="mb-4">
        <h6 class="text-uppercase text-muted small fw-bold mb-3 ps-1">Pipeline Health</h6>
        <div class="stat-card">
            <div class="stat-label">Total Value</div>
            <div class="stat-value" id="kpiTotalAmount">$0</div>
            <div class="stat-sub">Active Opps</div>
        </div>
        <div class="stat-card" style="border-left-color: #0d6efd;">
            <div class="stat-label">Active Items</div>
            <div class="stat-value" id="kpiTotalCount">0</div>
            <div class="stat-sub">Engagements</div>
        </div>
    </div>

    <div class="mb-4" id="districtWidgetWrap" style="display:none;">
        <h6 class="text-uppercase text-muted small fw-bold mb-2 ps-1">TRRs by District</h6>
        <div class="stat-card p-2" style="border-left-color: #6f42c1;">
            <div id="districtList" style="font-size: 0.85rem;"></div>
        </div>
    </div>

    <div class="mb-4" id="fyWidgetWrap" style="display:none;">
        <h6 class="text-uppercase text-muted small fw-bold mb-2 ps-1">This FY <span class="text-muted fw-normal" id="fyLabel"></span></h6>
        <div class="stat-card p-2" style="border-left-color: #198754;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label mb-0">Won</div>
                    <div class="stat-value" style="font-size:1.1rem;"><span id="fyWonCount">0</span> <small class="text-muted" style="font-size:.7rem;">/ <span id="fyClosedCount">0</span></small></div>
                </div>
                <div class="text-end">
                    <div class="stat-label mb-0">Win Rate</div>
                    <div class="stat-value" style="font-size:1.1rem;" id="fyWinRate">—</div>
                </div>
            </div>
            <div class="mt-2" style="height:4px; background:#eee; border-radius:2px;">
                <div id="fyWinBar" style="height:100%; width:0%; background:#198754; border-radius:2px; transition: width .3s;"></div>
            </div>
            <div class="small text-success mt-1" id="fyWonAmount" style="font-weight:600;">$0</div>
        </div>
    </div>

    <div class="mb-4">
        <h6 class="text-uppercase text-muted small fw-bold mb-2 ps-1">Quick Views</h6>
        <a href="#" class="nav-link-sidebar" id="linkAll" onclick="applyQuickFilter('all')">
            <i class="fas fa-layer-group"></i> All Active
        </a>
        <a href="#" class="nav-link-sidebar" id="linkMy" onclick="applyQuickFilter('my_active')">
            <i class="fas fa-user-check"></i> My Active PoVs
        </a>
        <a href="#" class="nav-link-sidebar" id="linkTop" onclick="applyQuickFilter('high_value')">
            <i class="fas fa-sack-dollar"></i> Top Opps
        </a>
        <a href="#" class="nav-link-sidebar text-danger" id="linkRisk" onclick="applyQuickFilter('at_risk')">
            <i class="fas fa-exclamation-circle"></i> At Risk / Critical
        </a>
        <a href="#" class="nav-link-sidebar text-warning" id="linkPending" onclick="applyQuickFilter('pending_outcome')">
            <i class="fas fa-clipboard-question"></i> Outcome Pending
            <span class="badge bg-warning text-dark ms-auto" id="pendingBadge" style="display:none; font-size:.65rem;">0</span>
        </a>
    </div>

    <a href="../index.php" class="btn btn-back">⬅️ Back to PANTools</a>
</div>

<div class="main-wrapper">
    
    <div class="top-navbar">
        <ul class="nav nav-pills" id="viewTabs">
            <li class="nav-item">
                <a class="nav-link active" href="#" onclick="showMetrics()" id="tabMetrics"><i class="fas fa-chart-line me-1"></i> Dashboard</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#" onclick="showDashboard()" id="tabList"><i class="fas fa-list me-1"></i> List View</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#" onclick="showTimeline()" id="tabTimeline"><i class="fas fa-chart-gantt me-1"></i> Timeline</a>
            </li>
        </ul>

        <div class="d-flex align-items-center gap-2">
            <div class="dropdown">
                <button class="btn btn-outline-secondary dropdown-toggle btn-sm" type="button" id="actionsDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-sliders-h me-1"></i> Actions
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0" aria-labelledby="actionsDropdown">
                    <li><h6 class="dropdown-header text-uppercase small ls-1">Data Management</h6></li>
                    <li>
                         <input type="file" id="importSFDCFile" accept=".csv" style="display: none;" onchange="importSFDC(this)">
                        <a class="dropdown-item" href="#" onclick="document.getElementById('importSFDCFile').click()">
                            <i class="fas fa-cloud-download-alt text-success me-2"></i> Import from SFDC
                        </a>
                    </li>
                    <li>
                         <input type="file" id="importFile" accept=".json" style="display: none;" multiple onchange="importData(this)">
                        <a class="dropdown-item" href="#" onclick="document.getElementById('importFile').click()">
                            <i class="fas fa-file-import text-primary me-2"></i> Restore JSON Backup
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="#" onclick="exportData(event)">
                            <i class="fas fa-file-export text-secondary me-2"></i> Export JSON Data
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li><h6 class="dropdown-header text-uppercase small ls-1">System</h6></li>
                    <li>
                        <a class="dropdown-item" href="#" onclick="resetUser()">
                            <i class="fas fa-user-cog me-2"></i> Change Default Owner
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="#" onclick="openCompSettings()">
                            <i class="fas fa-euro-sign me-2"></i> Compensation Plan &amp; FY
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item text-danger" href="#" onclick="deleteAllData()">
                            <i class="fas fa-trash-alt me-2"></i> Delete All Data
                        </a>
                    </li>
                </ul>
            </div>

            <button class="btn btn-primary btn-sm px-3 shadow-sm" onclick="showCreateForm()">
                <i class="fas fa-plus me-1"></i> New TRR
            </button>
        </div>
    </div>

    <div id="globalFilters" class="filter-bar">
        <div class="row align-items-center g-2">
            <div class="col-md-3">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 ps-0" id="globalSearch" placeholder="Filter by Account or Owner..." onkeyup="refreshActiveView()">
                </div>
            </div>
            <div class="col-md-9">
                <div class="d-flex flex-wrap gap-1 align-items-center justify-content-md-end">
                    
                    <small class="text-muted fw-bold me-1">Type:</small>
                    <input type="checkbox" class="btn-check eng-filter" id="btnOpp" value="Opportunity" checked onchange="refreshActiveView()">
                    <label class="btn btn-sm btn-outline-info" for="btnOpp">Opp</label>

                    <input type="checkbox" class="btn-check eng-filter" id="btnPost" value="Post Sales" checked onchange="refreshActiveView()">
                    <label class="btn btn-sm btn-outline-purple" for="btnPost">Post</label>

                    <input type="checkbox" class="btn-check eng-filter" id="btnEvent" value="Events" checked onchange="refreshActiveView()">
                    <label class="btn btn-sm btn-outline-warning" for="btnEvent">Event</label>

                    <div class="border-start mx-2 ps-2 d-flex align-items-center gap-1">
                        <small class="text-muted fw-bold me-1">Status:</small>
                        <input type="checkbox" class="btn-check status-filter" id="btnOnTrack" value="On Track" checked onchange="refreshActiveView()">
                        <label class="btn btn-sm btn-outline-success" for="btnOnTrack">On Track</label>

                        <input type="checkbox" class="btn-check status-filter" id="btnAtRisk" value="At Risk" checked onchange="refreshActiveView()">
                        <label class="btn btn-sm btn-outline-warning" for="btnAtRisk">At Risk</label>

                        <input type="checkbox" class="btn-check status-filter" id="btnPlanned" value="Planned" checked onchange="refreshActiveView()">
                        <label class="btn btn-sm btn-outline-primary" for="btnPlanned">Planned</label>

                        <input type="checkbox" class="btn-check status-filter" id="btnNotStarted" value="Not Started" onchange="refreshActiveView()">
                        <label class="btn btn-sm btn-outline-secondary" for="btnNotStarted">Not Started</label>

                        <input type="checkbox" class="btn-check status-filter" id="btnParked" value="Parked" onchange="refreshActiveView()">
                        <label class="btn btn-sm btn-outline-dark" style="opacity: 0.7;" for="btnParked">Parked</label>

                        <div class="border-start ms-2 ps-2">
                            <input type="checkbox" class="btn-check status-filter" id="btnClosed" value="Closed" onchange="refreshActiveView()">
                            <label class="btn btn-sm btn-dark" for="btnClosed">Closed</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="content-area">

        <!-- ═══ METRICS DASHBOARD ═══ -->
        <div id="metricsView" class="view-section active">
            <!-- Filters -->
            <div class="card p-3 mb-3">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small mb-1 fw-bold">Date Range</label>
                        <select class="form-select form-select-sm" id="mfDateRange" onchange="onMetricsFiltersChanged()">
                            <option value="fy_current" selected>Current FY</option>
                            <option value="fy_last">Last FY</option>
                            <option value="ytd">Year to Date (calendar)</option>
                            <option value="q_current">Current Quarter</option>
                            <option value="q_last">Last Quarter</option>
                            <option value="last_90">Last 90 days</option>
                            <option value="last_365">Last 365 days</option>
                            <option value="all">All time</option>
                            <option value="custom">Custom...</option>
                        </select>
                    </div>
                    <div class="col-md-2" id="mfCustomFromWrap" style="display:none;">
                        <label class="form-label small mb-1">From</label>
                        <input type="date" class="form-control form-control-sm" id="mfFrom" onchange="onMetricsFiltersChanged()">
                    </div>
                    <div class="col-md-2" id="mfCustomToWrap" style="display:none;">
                        <label class="form-label small mb-1">To</label>
                        <input type="date" class="form-control form-control-sm" id="mfTo" onchange="onMetricsFiltersChanged()">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1 fw-bold">Product</label>
                        <select class="form-select form-select-sm" id="mfProduct" onchange="onMetricsFiltersChanged()">
                            <option value="">All products</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1 fw-bold">Owner</label>
                        <select class="form-select form-select-sm" id="mfOwner" onchange="onMetricsFiltersChanged()">
                            <option value="">All owners</option>
                        </select>
                    </div>
                </div>
                <div class="mt-2 d-flex justify-content-between align-items-center">
                    <small class="text-muted" id="mfSummary">–</small>
                    <div>
                        <button class="btn btn-outline-secondary btn-sm" onclick="exportMetricsCSV()"><i class="fas fa-file-csv"></i> Export CSV</button>
                    </div>
                </div>
            </div>

            <!-- Engagement mix (counts by type in range) -->
            <div class="row g-2 mb-3" id="engagementMix"></div>

            <!-- KPI tiles -->
            <div class="row g-3 mb-3" id="metricsKPIs"></div>

            <!-- Charts row 1 -->
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <div class="card p-3 h-100">
                        <h6 class="fw-bold mb-2">Commercial Outcomes</h6>
                        <div id="chartCommercial" style="min-height:220px;"></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card p-3 h-100">
                        <h6 class="fw-bold mb-2">Technical Outcomes</h6>
                        <div id="chartTechnical" style="min-height:220px;"></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card p-3 h-100">
                        <h6 class="fw-bold mb-2">Tech × Commercial Matrix</h6>
                        <div id="chartMatrix" style="min-height:220px;"></div>
                    </div>
                </div>
            </div>

            <!-- Charts row 2 -->
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="card p-3 h-100">
                        <h6 class="fw-bold mb-2">Won amount by month</h6>
                        <div id="chartByMonth" style="min-height:260px;"></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card p-3 h-100">
                        <h6 class="fw-bold mb-2">Win rate by product</h6>
                        <div id="chartByProduct" style="min-height:260px;"></div>
                    </div>
                </div>
            </div>

            <!-- Charts row 3 -->
            <div class="row g-3 mb-3">
                <div class="col-md-12">
                    <div class="card p-3">
                        <h6 class="fw-bold mb-2">Loss Reasons Distribution</h6>
                        <div id="chartLossReasons" style="min-height:220px;"></div>
                    </div>
                </div>
            </div>

            <!-- Charts row 4 — District breakdown (stacked) -->
            <div class="row g-3 mb-3">
                <div class="col-md-12">
                    <div class="card p-3">
                        <div class="mb-3">
                            <h6 class="fw-bold mb-0"><i class="fas fa-map-marker-alt me-1"></i> District Breakdown</h6>
                            <div class="small text-muted">Click on each chart's legend to hide/show categories.</div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="small text-muted mb-1">TRR count by district</div>
                                <div id="chartDistrictCount" style="min-height:260px;"></div>
                            </div>
                            <div class="col-md-6">
                                <div class="small text-muted mb-1">Amount by district ($)</div>
                                <div id="chartDistrictAmount" style="min-height:260px;"></div>
                            </div>
                        </div>
                        <div class="small text-muted mt-2" id="districtFooterHint"></div>
                    </div>
                </div>
            </div>

            <!-- Compensation section -->
            <hr class="my-4">
            <h5 class="mb-3" style="color: var(--primary-color);">
                <i class="fas fa-euro-sign"></i> Compensation
                <small class="text-muted fw-normal">Individual attainment and estimated variable payout for the filter range</small>
                <a href="#" class="small ms-2" onclick="openCompSettings(); return false;" title="Edit plan"><i class="fas fa-cog"></i></a>
            </h5>
            <div id="compEmptyHint" class="alert alert-warning small mb-3" style="display:none;">
                <i class="fas fa-info-circle"></i> Configure your <strong>Compensation Plan</strong> (menu <em>Tools → Compensation Plan</em>) to see these widgets.
            </div>
            <div class="row g-3 mb-3" id="compKPIs"></div>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="card p-3">
                        <h6 class="fw-bold mb-2">Quota attainment (individual)</h6>
                        <div id="compAttainmentBar"></div>
                        <div class="small text-muted mt-2" id="compAttainmentSub">–</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card p-3">
                        <h6 class="fw-bold mb-2">Group share <small class="text-muted fw-normal">(my retirement / group quota)</small></h6>
                        <div id="compGroupShareBar"></div>
                        <div class="small text-muted mt-2" id="compGroupShareSub">–</div>
                    </div>
                </div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="card p-3">
                        <h6 class="fw-bold mb-2">Bookings by month <small class="text-muted fw-normal">(Upsell+NetNew vs Renew)</small></h6>
                        <div id="compChartMonth" style="min-height:280px;"></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card p-3">
                        <h6 class="fw-bold mb-2">Cumulative attainment <small class="text-muted fw-normal">(variable_credit / individual_target)</small></h6>
                        <div id="compChartCumulative" style="min-height:280px;"></div>
                    </div>
                </div>
            </div>

            <!-- Support Activities section -->
            <hr class="my-4">
            <h5 class="mb-3" style="color: var(--primary-color);">
                <i class="fas fa-hands-helping"></i> Support Activities
                <small class="text-muted fw-normal">Post Sales & Events (not counted as Won/Lost)</small>
            </h5>
            <div class="row g-3 mb-3" id="supportKPIs"></div>
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="card p-3">
                        <h6 class="fw-bold mb-2">Top 10 accounts <small class="text-muted fw-normal">(by count)</small></h6>
                        <div id="chartAccounts" style="min-height:280px;"></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card p-3">
                        <h6 class="fw-bold mb-2">Top 10 accounts <small class="text-muted fw-normal">(by amount)</small></h6>
                        <div id="chartAccountsAmount" style="min-height:280px;"></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card p-3">
                        <h6 class="fw-bold mb-2">Support activity split</h6>
                        <div id="chartSupportSplit" style="min-height:280px;"></div>
                    </div>
                </div>
            </div>
        </div>

        <div id="dashboardView" class="view-section">
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header bg-white border-bottom-0 pt-3 pb-2">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-bold"><i class="fas fa-calendar-alt me-2 text-primary"></i> Team Workload Forecast (4 Weeks)</span>
                            </div>
                            
                            <div class="d-flex flex-wrap bg-light p-2 rounded border">
                                <span class="fw-bold me-3 text-uppercase" style="font-size:0.7rem; letter-spacing:1px; color:#777;">Legend:</span>
                                <div class="legend-item"><span class="legend-icon">💤</span> Free</div>
                                <div class="legend-item"><span class="legend-icon">🟢</span> Healthy</div>
                                <div class="legend-item"><span class="legend-icon">🟡</span> Busy</div>
                                <div class="legend-item"><span class="legend-icon">🥵</span> High</div>
                                <div class="legend-item"><span class="legend-icon">🔥</span> Burnout</div>
                            </div>
                        </div>
                        
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-bordered mb-0 table-fixed">
                                    <thead class="table-light text-center">
                                        <tr id="forecastHeader">
                                            <th class="text-start ps-3" style="width: 20%;">Owner</th>
                                            </tr>
                                    </thead>
                                    <tbody id="forecastBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle table-fixed mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th style="width: 20%; padding:15px;">Engagement / Product</th>
                                <th style="width: 20%; padding:15px;">Account</th>
                                <th style="width: 10%; padding:15px;">Value</th>
                                <th style="width: 10%; padding:15px;">Status</th>
                                <th style="width: 15%; padding:15px;">Timeline</th>
                                <th style="width: 15%; padding:15px;">Progress</th>
                                <th style="width: 10%; padding:15px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="trrTableBody"></tbody>
                    </table>
                </div>
                <div id="emptyState" class="text-center py-5 text-muted" style="display:none;">
                    <h4>No engagements match your filters</h4>
                    <p>Try adjusting the filters above or click "New TRR".</p>
                </div>
            </div>
        </div>

        <div id="timelineView" class="view-section">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h3>Global Timeline</h3>
                <div>
                    <small class="me-2 text-muted fw-bold">Effort:</small>
                    <span class="badge" style="background-color:#198754">Low</span>
                    <span class="badge" style="background-color:#ffc107; color:black">Mod</span>
                    <span class="badge" style="background-color:#fd7e14">High</span>
                    <span class="badge" style="background-color:#dc3545">Crit</span>
                </div>
            </div>
            <div class="card p-4">
                <div id="timelineChart"></div>
                <div id="timelineEmpty" class="text-center py-5 text-muted" style="display:none;">
                    <h5>No data to display</h5>
                    <p>Ensure items match your filters and have dates assigned.</p>
                </div>
            </div>
        </div>

        <div id="formView" class="view-section">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3 id="formTitle">New Report</h3>
                <div>
                    <button type="submit" form="trrForm" class="btn btn-primary me-2">Save Report</button>
                    <button class="btn btn-outline-dark" onclick="showDashboard()">Cancel</button>
                </div>
            </div>

            <form id="trrForm" onsubmit="saveTRR(event)">
                <input type="hidden" id="trrId">
                <div class="card p-4">
                    <h5 class="mb-3" style="color: var(--primary-color);"><i class="fas fa-file-alt"></i> Project Information</h5>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">TRR ID / Name *</label>
                            <input type="text" class="form-control" id="trrName" required placeholder="e.g. TRR343434">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Creation Date *</label>
                            <input type="date" class="form-control" id="creationDate" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Account Name *</label>
                            <input type="text" class="form-control" id="accountName" required placeholder="Client Name">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold text-success">Opp Amount ($)</label>
                            <input type="number" class="form-control" id="oppAmount" placeholder="0.00" step="0.01">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Technology / Product</label>
                            <select class="form-select" id="cortexProduct" multiple size="5" required>
                                <option value="AgentiX">AgentiX</option>
                                <option value="XSIAM">XSIAM</option>
                                <option value="Email Security">Email Security</option>
                                <option value="Exposure Management">Exposure Management</option>
                                <option value="XDR/CDR">XDR/CDR</option>
                                <option value="Xpanse">Xpanse</option>
                                <option value="XSOAR">XSOAR</option>
                                <option value="APP Sec">APP Sec</option>
                                <option value="DSPM/AI">DSPM/AI</option>
                                <option value="Posture">Posture</option>
                                <option value="Runtime">Runtime</option>
                            </select>
                            <div class="form-text" style="font-size: 0.75rem;">Hold Ctrl/Cmd to select multiple.</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Engagement Type</label>
                            <select class="form-select" id="engagementType">
                                <option value="Opportunity" selected>Opportunity</option>
                                <option value="Post Sales">Post Sales</option>
                                <option value="Events">Events</option>
                            </select>
                            <label class="form-label fw-bold mt-2" style="color:var(--primary-color);">Presales / Owner</label>
                            <input type="text" class="form-control" id="ownerName" placeholder="Who is running this?">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select class="form-select" id="projectStatus" onchange="onStatusChanged()">
                                <option value="On Track">🟢 On Track</option>
                                <option value="At Risk">🟡 At Risk</option>
                                <option value="Planned">🔵 Planned</option>
                                <option value="Not Started">⚪ Not Started</option>
                                <option value="Parked">🟤 Parked</option>
                                <option value="Closed">⚫ Closed</option>
                            </select>
                            <label class="form-label fw-bold mt-2">Technical Outcome</label>
                            <select class="form-select" id="technicalOutcome">
                                <option value="">— Pending —</option>
                                <option value="Technical Win">🔧 Technical Win</option>
                                <option value="Partial">🔧 Partial</option>
                                <option value="Technical Loss">🔧 Technical Loss</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold"><i class="fas fa-map-marker-alt me-1 text-muted"></i>District</label>
                            <input type="text" class="form-control" id="district" list="districtsList" autocomplete="off">
                            <datalist id="districtsList"></datalist>
                            <div class="form-text small">Account Owner District. Autocomplete from existing ones.</div>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-bold text-primary">SFDC Links</label>
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <input type="url" class="form-control form-control-sm" id="sfdcTrrLink" placeholder="TRR Link">
                                </div>
                                <div class="col-md-4">
                                    <input type="url" class="form-control form-control-sm" id="sfdcOppLink" placeholder="Opportunity Link">
                                </div>
                                <div class="col-md-4">
                                    <input type="url" class="form-control form-control-sm" id="sfdcTechValLink" placeholder="Tech Validation Link">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ Close Details (visible cuando Status=Closed) ═══ -->
                    <div id="closeDetailsSection" style="display:none; margin-top:20px; padding:15px; background:#fff8e6; border-left:4px solid #f0ad4e; border-radius:6px;">
                        <h6 class="fw-bold mb-3" style="color:#b7791f;"><i class="fas fa-flag-checkered"></i> Close Details <small class="text-muted fw-normal">(required to close)</small></h6>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Actual Close Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="closedDate">
                                <div class="form-text small">Actual close date. Used to group by FY.</div>
                            </div>
                            <div class="col-md-3" id="commercialOutcomeWrap">
                                <label class="form-label fw-bold">Commercial Outcome <span class="text-danger">*</span></label>
                                <select class="form-select" id="commercialOutcome" onchange="onCommercialChanged()">
                                    <option value="">— select —</option>
                                    <option value="Won">🏆 Won</option>
                                    <option value="Lost">❌ Lost</option>
                                    <option value="No Decision">— No Decision</option>
                                </select>
                            </div>
                            <div class="col-md-3" id="finalAmountWrap" style="display:none;">
                                <label class="form-label fw-bold">Final Amount <span class="currency-label">(€)</span> <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="finalAmount" min="0" step="1000" placeholder="Adjusted amount at close" oninput="updateSplitHint()">
                                <div class="form-text small">Pre-filled with the original Opp Amount. Editable.</div>
                            </div>
                            <div class="col-md-3" id="renewAmountWrap" style="display:none;">
                                <label class="form-label fw-bold">Renew Amount <span class="currency-label">(€)</span></label>
                                <input type="number" class="form-control" id="renewAmount" min="0" step="1000" value="0" oninput="updateSplitHint()">
                                <div class="form-text small" id="renewSplitHint">Upsell+NetNew: — · Renew: —</div>
                            </div>
                            <div class="col-md-3" id="lossReasonWrap" style="display:none;">
                                <label class="form-label fw-bold">Loss Reason</label>
                                <select class="form-select" id="lossReason">
                                    <option value="">— select (optional) —</option>
                                </select>
                                <input type="text" class="form-control form-control-sm mt-1" id="lossReasonOther" placeholder="If Other, describe..." style="display:none;">
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <h5 class="mb-3" style="color: var(--primary-color);"><i class="fas fa-calendar-alt"></i> Planning & Complexity</h5>
                    <div class="row g-3">
                         <div class="col-md-3">
                            <label class="form-label">Est. Start Date</label>
                            <input type="date" class="form-control" id="startDate">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Est. End Date</label>
                            <input type="date" class="form-control" id="endDate">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Complexity</label>
                            <select class="form-select" id="complexity">
                                <option value="Low">Low</option>
                                <option value="Medium">Medium</option>
                                <option value="High">High</option>
                            </select>
                        </div>
                         <div class="col-md-3">
                            <label class="form-label">Workload Intensity</label>
                            <select class="form-select" id="workload">
                                <option value="Light">Light</option>
                                <option value="Normal">Normal</option>
                                <option value="Heavy">Heavy</option>
                            </select>
                        </div>
                    </div>
                    <hr class="my-4">
                    <h5 class="mb-3" style="color: var(--primary-color);"><i class="fas fa-chart-line"></i> Details</h5>
                    <div class="mb-3">
                        <label class="form-label">Progress / Accomplishments</label>
                        <textarea class="form-control" id="progress" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Next Steps</label>
                        <textarea class="form-control" id="nextSteps" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-danger"><i class="fas fa-exclamation-triangle"></i> Challenges / Blockers</label>
                        <textarea class="form-control" id="challenges" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Additional Comments</label>
                        <textarea class="form-control" id="comments" rows="2"></textarea>
                    </div>
                </div>
            </form>
        </div>

    </div></div><div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewModalLabel">Engagement Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
        </div>
    </div>
</div>

<!-- Compensation Plan Settings Modal -->
<div class="modal fade" id="compModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-euro-sign"></i> Compensation Plan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Base Salary Pay</label>
                        <div class="input-group">
                            <span class="input-group-text comp-cur-prefix">€</span>
                            <input type="number" class="form-control" id="compBase" min="0" step="1000" placeholder="e.g. 80000" oninput="updateCompDerived()">
                        </div>
                        <div class="form-text small">Fixed annual salary (BASE).</div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold">OTI (%)</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="compOtiPct" min="0" max="99" step="1" placeholder="20" oninput="updateCompDerived()">
                            <span class="input-group-text">%</span>
                        </div>
                        <div class="form-text small">Share of OTE.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Variable Target</label>
                        <input type="text" class="form-control bg-light" id="compVariableTarget" readonly>
                        <div class="form-text small">BASE × OTI/(100−OTI).</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">OTE (auto)</label>
                        <input type="text" class="form-control bg-light" id="compOte" readonly>
                        <div class="form-text small">BASE + Variable Target.</div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-bold">OTI Currency</label>
                        <select class="form-select" id="compCurrency">
                            <option value="EUR">€ EUR</option>
                            <option value="USD">$ USD</option>
                            <option value="GBP">£ GBP</option>
                        </select>
                        <div class="form-text small">Global display currency.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Fiscal Year starts on</label>
                        <select class="form-select" id="compFyMonth">
                            <option value="1">January</option><option value="2">February</option>
                            <option value="3">March</option><option value="4">April</option>
                            <option value="5">May</option><option value="6">June</option>
                            <option value="7">July</option><option value="8">August</option>
                            <option value="9">September</option><option value="10">October</option>
                            <option value="11">November</option><option value="12">December</option>
                        </select>
                        <div class="form-text small" id="compFyPreview">–</div>
                    </div>
                    <div class="col-md-4"></div>

                    <div class="col-12"><hr class="my-1"></div>

                    <div class="col-md-6">
                        <label class="form-label fw-bold">Group Annual Quota</label>
                        <div class="input-group">
                            <span class="input-group-text comp-cur-prefix">€</span>
                            <input type="number" class="form-control" id="compQuotaGroup" min="0" step="10000" placeholder="e.g. 38071947">
                        </div>
                        <div class="form-text small">Group annual quota (not individual).</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Group Size</label>
                        <input type="number" class="form-control" id="compGroupSize" min="1" step="1" placeholder="5">
                        <div class="form-text small">Presales in the group.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Individual Target</label>
                        <input type="text" class="form-control bg-light" id="compIndividualTarget" readonly>
                        <div class="form-text small">Auto: quota / N.</div>
                    </div>

                    <div class="col-12"><hr class="my-1"></div>

                    <div class="col-md-6">
                        <label class="form-label fw-bold">BCR Upsell + Net New</label>
                        <input type="number" class="form-control" id="compBcrNew" min="0" max="5" step="0.01" placeholder="1.00">
                        <div class="form-text small">Retirement/variable coefficient for new business.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">BCR Renewal</label>
                        <input type="number" class="form-control" id="compBcrRenew" min="0" max="5" step="0.01" placeholder="0.25">
                        <div class="form-text small">Renewal does not count toward quota but pays variable at this coefficient.</div>
                    </div>
                </div>

                <div class="alert alert-info small mt-3 mb-0">
                    <strong>How it is calculated:</strong><br>
                    · <strong>OTI% is a share of total OTE</strong>, not of BASE. With OTI=20% ⇒ BASE = 80% of OTE, OTI = 20% of OTE (BASE/4).<br>
                    · variable_target = BASE × OTI / (100 − OTI). OTE = BASE + variable_target.<br>
                    · quota_retirement = Upsell+NetNew × BCR_new. variable_credit = Upsell+NetNew × BCR_new + Renew × BCR_renew.<br>
                    · attainment% = variable_credit / individual_target. <strong>payout = variable_target × attainment%</strong>.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="saveCompSettings()">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Outcome Assignment Modal (para SFDC auto-close) -->
<div class="modal fade" id="bulkOutcomeModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="fas fa-flag-checkered"></i> Fill Outcomes for Auto-Closed TRRs</h5>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3">
                    Los siguientes TRRs se cerraron automáticamente al no aparecer en el SFDC export.
                    Rellena Technical Outcome, Commercial Outcome (para Opps), Final Amount y Loss Reason (opcional).
                    Puedes saltarte alguno y quedará marcado con badge <span class="badge bg-warning text-dark">⚠ Outcome pending</span> para completarlo después.
                </p>
                <div id="bulkOutcomeList"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" onclick="closeBulkOutcomeModal(false)">Skip all (leave pending)</button>
                <button type="button" class="btn btn-primary" onclick="closeBulkOutcomeModal(true)"><i class="fas fa-save"></i> Save filled outcomes</button>
            </div>
        </div>
    </div>
</div>

<script>
    let trrList = [];
    let chartInstance = null;
    let defaultOwner = '';
    let currentSort = 'date';
    let quickFilterMode = null; // null | 'pending_outcome'

    // Un TRR tiene outcome pendiente si está Closed pero le falta Technical Outcome
    // (o Commercial Outcome cuando es Opportunity)
    function isOutcomePending(item) {
        if ((item.projectStatus || '') !== 'Closed') return false;
        if (!item.technicalOutcome) return true;
        const isOpp = (item.engagementType || 'Opportunity') === 'Opportunity';
        if (isOpp && !item.commercialOutcome) return true;
        return false;
    }

    // ═══ Outcome constants ═══════════════════════════════════════════════
    const TECH_OUTCOMES     = ['Technical Win', 'Partial', 'Technical Loss'];
    const COMMERCIAL_OUTCOMES = ['Won', 'Lost', 'No Decision'];
    const LOSS_REASONS = [
        'Price / Budget',
        'Competitor / Alternative solution',
        'Timing / Postponed',
        'Feature gap',
        'Poor fit / Requirements changed',
        'Champion left / Internal politics',
        'Insufficient PoV time',
        'Regulatory / Compliance',
        'Other',
    ];
    function isOutcomePending(item) {
        if (item.projectStatus !== 'Closed') return false;
        if (!item.closedDate) return true;
        if (!item.technicalOutcome) return true;
        if ((item.engagementType || 'Opportunity') === 'Opportunity') {
            if (!item.commercialOutcome) return true;
            if (item.commercialOutcome === 'Won' && !(parseFloat(item.finalAmount) > 0)) return true;
        }
        return false;
    }
    // ═══ Close Details show/hide ══════════════════════════════════════════
    function populateLossReasons() {
        const sel = document.getElementById('lossReason');
        if (!sel || sel.dataset.populated) return;
        LOSS_REASONS.forEach(r => {
            const o = document.createElement('option');
            o.value = r; o.text = r;
            sel.appendChild(o);
        });
        sel.dataset.populated = '1';
        sel.addEventListener('change', () => {
            document.getElementById('lossReasonOther').style.display = sel.value === 'Other' ? '' : 'none';
        });
    }
    function onStatusChanged() {
        const status = document.getElementById('projectStatus').value;
        const type   = document.getElementById('engagementType').value;
        const isClosed = status === 'Closed';
        const isOpp    = type === 'Opportunity';
        const box = document.getElementById('closeDetailsSection');
        box.style.display = isClosed ? '' : 'none';
        // Prefill closedDate con hoy si abrimos la sección y aún no hay fecha
        if (isClosed) {
            const cd = document.getElementById('closedDate');
            if (cd && !cd.value) cd.value = getTodayLocalISO();
        }
        // Fields only meaningful for Opps
        document.getElementById('commercialOutcomeWrap').style.display = isClosed && isOpp ? '' : 'none';
        onCommercialChanged();
    }
    function onCommercialChanged() {
        const status = document.getElementById('projectStatus').value;
        const type   = document.getElementById('engagementType').value;
        const isClosed = status === 'Closed';
        const isOpp    = type === 'Opportunity';
        const comm     = document.getElementById('commercialOutcome').value;
        const finalWrap = document.getElementById('finalAmountWrap');
        const renewWrap = document.getElementById('renewAmountWrap');
        const lossWrap  = document.getElementById('lossReasonWrap');
        const showAmt = isClosed && isOpp && comm === 'Won';
        finalWrap.style.display = showAmt ? '' : 'none';
        renewWrap.style.display = showAmt ? '' : 'none';
        lossWrap.style.display  = isClosed && (comm === 'Lost' || comm === 'No Decision' ||
                                                document.getElementById('technicalOutcome').value === 'Technical Loss') ? '' : 'none';
        // Prefill finalAmount from oppAmount on first show
        const fa = document.getElementById('finalAmount');
        if (comm === 'Won' && !fa.value) {
            const orig = parseFloat(document.getElementById('oppAmount').value) || 0;
            if (orig > 0) fa.value = orig;
        }
        updateSplitHint();
    }
    function updateSplitHint() {
        const hint = document.getElementById('renewSplitHint');
        if (!hint) return;
        const total = parseFloat(document.getElementById('finalAmount').value) || 0;
        const renew = parseFloat(document.getElementById('renewAmount').value) || 0;
        const newBiz = total - renew;
        const over = renew > total;
        hint.innerHTML = over
            ? `<span class="text-danger fw-bold">⚠ Renew (${fmtMoney(renew)}) cannot exceed Final Amount (${fmtMoney(total)})</span>`
            : `Upsell+NetNew: <strong>${fmtMoney(newBiz)}</strong> · Renew: <strong>${fmtMoney(renew)}</strong>`;
    }
    // Also reveal loss reason when technicalOutcome changes to Loss
    document.addEventListener('DOMContentLoaded', () => {
        populateLossReasons();
        const te = document.getElementById('technicalOutcome');
        if (te) te.addEventListener('change', onCommercialChanged);
        const et = document.getElementById('engagementType');
        if (et) et.addEventListener('change', onStatusChanged);
    });

    // ═══ Bulk Outcome Modal (SFDC auto-close) ════════════════════════════
    let bulkOutcomeIds = [];
    function openBulkOutcomeModal(ids) {
        bulkOutcomeIds = ids.slice();
        const box = document.getElementById('bulkOutcomeList');
        box.innerHTML = '';
        ids.forEach((id, idx) => {
            const item = trrList.find(t => t.id === id);
            if (!item) return;
            const isOpp = (item.engagementType || 'Opportunity') === 'Opportunity';
            const opts = LOSS_REASONS.map(r => `<option value="${r}">${r}</option>`).join('');
            const rowId = 'bulk-' + idx;
            box.insertAdjacentHTML('beforeend', `
                <div class="card mb-2" data-idx="${idx}" data-id="${id}">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between mb-2">
                            <div>
                                <strong>${item.trrName || id}</strong>
                                <span class="text-muted small ms-2">${item.accountName || ''} · ${item.ownerName || ''}</span>
                                <span class="badge ${isOpp ? 'bg-info' : 'bg-secondary'} ms-2">${item.engagementType || 'Opportunity'}</span>
                            </div>
                            <span class="badge bg-light text-dark border">${item.id}</span>
                        </div>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-2">
                                <label class="form-label small mb-0">Close date</label>
                                <input type="date" class="form-control form-control-sm bulk-close-date" id="${rowId}-cd" value="${item.closedDate || getTodayLocalISO()}">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small mb-0">Technical</label>
                                <select class="form-select form-select-sm bulk-tech" id="${rowId}-tech">
                                    <option value="">— pending —</option>
                                    <option>Technical Win</option><option>Partial</option><option>Technical Loss</option>
                                </select>
                            </div>
                            ${isOpp ? `
                            <div class="col-md-2">
                                <label class="form-label small mb-0">Commercial</label>
                                <select class="form-select form-select-sm bulk-comm" id="${rowId}-comm">
                                    <option value="">— pending —</option>
                                    <option>Won</option><option>Lost</option><option>No Decision</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small mb-0">Final ${currencySymbol()}</label>
                                <input type="number" class="form-control form-control-sm bulk-amount" id="${rowId}-amt" value="${item.oppAmount || ''}" min="0" step="1000">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small mb-0">Renew ${currencySymbol()}</label>
                                <input type="number" class="form-control form-control-sm bulk-renew" id="${rowId}-renew" value="0" min="0" step="1000">
                            </div>` : '<div class="col-md-6"></div>'}
                            <div class="col-md-3">
                                <label class="form-label small mb-0">Loss reason (optional)</label>
                                <select class="form-select form-select-sm bulk-loss" id="${rowId}-loss">
                                    <option value="">—</option>${opts}
                                </select>
                            </div>
                            <div class="col-md-1 text-end">
                                <a href="#" class="small text-danger" onclick="removeBulkRow(${idx}); return false;" title="Skip this one">Skip</a>
                            </div>
                        </div>
                    </div>
                </div>
            `);
        });
        new bootstrap.Modal(document.getElementById('bulkOutcomeModal')).show();
    }
    function removeBulkRow(idx) {
        const row = document.querySelector(`#bulkOutcomeList [data-idx="${idx}"]`);
        if (row) row.remove();
    }
    function closeBulkOutcomeModal(save) {
        if (save) {
            document.querySelectorAll('#bulkOutcomeList .card').forEach(row => {
                const id = row.dataset.id;
                const item = trrList.find(t => t.id === id);
                if (!item) return;
                const cd    = row.querySelector('.bulk-close-date')?.value || '';
                const tech  = row.querySelector('.bulk-tech')?.value || '';
                const comm  = row.querySelector('.bulk-comm')?.value || '';
                const amt   = row.querySelector('.bulk-amount')?.value || '';
                const renew = row.querySelector('.bulk-renew')?.value || '';
                const loss  = row.querySelector('.bulk-loss')?.value || '';
                if (cd)   item.closedDate = cd;
                if (tech) item.technicalOutcome = tech;
                if (comm) item.commercialOutcome = comm;
                if (comm === 'Won' && amt) {
                    item.finalAmount = amt;
                    const rn = parseFloat(renew) || 0;
                    const fa = parseFloat(amt) || 0;
                    item.renewAmount = String(Math.max(0, Math.min(rn, fa)));
                }
                if (loss) item.lossReason = loss;
            });
            saveToStorage();
        }
        bootstrap.Modal.getInstance(document.getElementById('bulkOutcomeModal')).hide();
        bulkOutcomeIds = [];
        showDashboard();
    }

    function outcomeBadge(item) {
        if (item.projectStatus !== 'Closed') return '';
        const parts = [];
        if (isOutcomePending(item)) parts.push('<span class="badge bg-warning text-dark" title="Outcome pending">⚠ Outcome pending</span>');
        if (item.commercialOutcome === 'Won')  parts.push('<span class="badge bg-success">🏆 Won</span>');
        if (item.commercialOutcome === 'Lost') parts.push('<span class="badge bg-danger">❌ Lost</span>');
        if (item.commercialOutcome === 'No Decision') parts.push('<span class="badge bg-secondary">— No Decision</span>');
        if (item.technicalOutcome === 'Technical Win')  parts.push('<span class="badge" style="background:#0d6efd">🔧 Tech Win</span>');
        if (item.technicalOutcome === 'Technical Loss') parts.push('<span class="badge" style="background:#6f1d1b">🔧 Tech Loss</span>');
        if (item.technicalOutcome === 'Partial')        parts.push('<span class="badge" style="background:#795548">🔧 Tech Partial</span>');
        return parts.join(' ');
    }

    document.addEventListener('DOMContentLoaded', () => {
        loadFromStorage();
        checkDefaultOwner();
        showMetrics();
        const dateInput = document.getElementById('creationDate');
        if(dateInput) dateInput.valueAsDate = new Date();
    });

    function loadFromStorage() {
        const data = localStorage.getItem('pov_radar_data');
        if (data) trrList = JSON.parse(data);
        // PANTools shared identity (fallback a la key vieja para retrocompatibilidad)
        const sharedOwner = (localStorage.getItem('pantools_user_name') || '').trim();
        const legacyOwner = (localStorage.getItem('pov_radar_default_owner') || '').trim();
        defaultOwner = sharedOwner || legacyOwner || '';
        if (defaultOwner) {
            // Sincroniza ambas keys por si acaso
            localStorage.setItem('pantools_user_name', defaultOwner);
            localStorage.setItem('pov_radar_default_owner', defaultOwner);
        }
        renderSidebarStats();
        renderUserBadge();
    }

    function checkDefaultOwner() {
        if (!defaultOwner) {
            setTimeout(() => {
                const name = prompt("Welcome to PoV Radar!\nPlease enter your name (Presales/Owner):");
                if (name && name.trim() !== "") {
                    defaultOwner = name.trim();
                    localStorage.setItem('pantools_user_name', defaultOwner);
                    localStorage.setItem('pov_radar_default_owner', defaultOwner);
                    renderUserBadge();
                }
            }, 500);
        }
    }

    function renderUserBadge() {
        const badge = document.getElementById('userBadge');
        const nameEl = document.getElementById('userNameDisplay');
        if (defaultOwner) {
            nameEl.innerText = defaultOwner;
            badge.style.display = 'block';
        } else {
            badge.style.display = 'none';
        }
    }

    function resetUser() {
        if(confirm("Change user?")) {
            localStorage.removeItem('pov_radar_default_owner');
            localStorage.removeItem('pantools_user_name');
            location.reload();
        }
    }

    function deleteAllData() {
        if(!confirm("⚠ WARNING: DELETE ALL DATA?\n\nThis will remove:\n· All TRRs\n· Fiscal Year setting\n· Compensation Plan (BASE, OTI, quota, BCRs…)\n· Forecast snapshots\n\nUser identity is preserved.")) return;
        trrList = [];
        localStorage.setItem('pov_radar_data', JSON.stringify(trrList));
        // Reset app-specific settings & snapshots
        localStorage.removeItem('pov_radar_fy_start_month');
        localStorage.removeItem('pov_radar_comp_plan');
        localStorage.removeItem('pov_radar_forecast_snapshots');
        // Repaint everything — sidebar widgets, currency labels, dashboard, form labels
        document.querySelectorAll('.currency-label').forEach(el => el.textContent = '(' + currencySymbol() + ')');
        refreshActiveView();
        renderSidebarStats();
        if (document.getElementById('metricsView').classList.contains('active')) renderMetrics();
    }

    function saveToStorage() {
        localStorage.setItem('pov_radar_data', JSON.stringify(trrList));
        refreshActiveView();
        renderSidebarStats();
    }

    function hideAllViews() {
        document.querySelectorAll('.view-section').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.nav-link').forEach(el => el.classList.remove('active'));
    }

    function showDashboard() {
        hideAllViews();
        document.getElementById('dashboardView').classList.add('active');
        document.getElementById('tabList').classList.add('active');
        document.getElementById('globalFilters').style.display = 'block';
        renderTable();
    }

    function showMetrics() {
        hideAllViews();
        document.getElementById('metricsView').classList.add('active');
        document.getElementById('tabMetrics').classList.add('active');
        document.getElementById('globalFilters').style.display = 'none';
        renderMetrics();
    }

    function showTimeline() {
        hideAllViews();
        document.getElementById('timelineView').classList.add('active');
        document.getElementById('tabTimeline').classList.add('active');
        document.getElementById('globalFilters').style.display = 'block';
        requestAnimationFrame(renderGlobalTimeline);
    }

    function showCreateForm(reset = true) {
    hideAllViews();
    document.getElementById('globalFilters').style.display = 'none';

    // Popular datalist de distritos con los que ya existen en trrList
    const dl = document.getElementById('districtsList');
    if (dl) {
        const districts = [...new Set(trrList.map(t => (t.district || '').trim()).filter(Boolean))].sort();
        dl.innerHTML = districts.map(d => `<option value="${d.replace(/"/g,'&quot;')}"></option>`).join('');
    }

    if (reset) {
        document.getElementById('trrForm').reset();
        document.getElementById('trrId').value = '';
        document.getElementById('formTitle').innerText = 'New Report';
        document.getElementById('creationDate').valueAsDate = new Date();
        document.getElementById('complexity').value = 'Medium';
        document.getElementById('workload').value = 'Normal';
        document.getElementById('engagementType').value = 'Opportunity';
        document.getElementById('oppAmount').value = '';
        document.getElementById('district').value = '';
        // Reset new outcome fields
        document.getElementById('technicalOutcome').value = '';
        document.getElementById('commercialOutcome').value = '';
        document.getElementById('finalAmount').value = '';
        document.getElementById('renewAmount').value = 0;
        document.getElementById('lossReason').value = '';
        document.getElementById('lossReasonOther').value = '';
        document.getElementById('lossReasonOther').style.display = 'none';
        document.getElementById('closedDate').value = '';

        const select = document.getElementById('cortexProduct');
        Array.from(select.options).forEach(opt => opt.selected = false);

        if(defaultOwner) document.getElementById('ownerName').value = defaultOwner;
    }
    // Refresca etiquetas (€/$/£) según currency configurada
    document.querySelectorAll('.currency-label').forEach(el => el.textContent = '(' + currencySymbol() + ')');
    onStatusChanged();
    updateSplitHint();
    document.getElementById('formView').classList.add('active');
    }



    function applyQuickFilter(type) {
        document.getElementById('globalSearch').value = '';
        document.querySelectorAll('.status-filter').forEach(c => c.checked = true);
        document.querySelectorAll('.eng-filter').forEach(c => c.checked = true);
        document.getElementById('btnClosed').checked = false;

        document.querySelectorAll('.nav-link-sidebar').forEach(l => l.classList.remove('active'));
        currentSort = 'date';
        quickFilterMode = null;

        if (type === 'all') {
            document.getElementById('linkAll').classList.add('active');
        }
        else if (type === 'my_active') {
            document.getElementById('linkMy').classList.add('active');
            document.getElementById('globalSearch').value = defaultOwner;
        }
        else if (type === 'high_value') {
            document.getElementById('linkTop').classList.add('active');
            document.querySelectorAll('.eng-filter').forEach(c => c.checked = false);
            document.getElementById('btnOpp').checked = true;
            currentSort = 'amount'; // Enable Amount Sort
        }
        else if (type === 'at_risk') {
            document.getElementById('linkRisk').classList.add('active');
            document.querySelectorAll('.status-filter').forEach(c => c.checked = false);
            document.getElementById('btnAtRisk').checked = true;
        }
        else if (type === 'pending_outcome') {
            document.getElementById('linkPending').classList.add('active');
            // Sólo Closed: dejar todas las categorías de status desmarcadas + marcar Closed
            document.querySelectorAll('.status-filter').forEach(c => c.checked = false);
            document.getElementById('btnClosed').checked = true;
            quickFilterMode = 'pending_outcome';
        }

        // Asegura que estamos en List View para que se apliquen los filtros
        if (!document.getElementById('dashboardView').classList.contains('active') &&
            !document.getElementById('timelineView').classList.contains('active')) {
            showDashboard();
        } else {
            refreshActiveView();
        }
    }

    function getFilteredData() {
        const searchVal = document.getElementById('globalSearch').value.toLowerCase();
        
        const statusCheckboxes = document.querySelectorAll('.status-filter:checked');
        const selectedStatuses = Array.from(statusCheckboxes).map(cb => cb.value);

        const engCheckboxes = document.querySelectorAll('.eng-filter:checked');
        const selectedTypes = Array.from(engCheckboxes).map(cb => cb.value);

        return trrList.filter(item => {
            const matchesSearch = item.accountName.toLowerCase().includes(searchVal) ||
                                  (item.ownerName && item.ownerName.toLowerCase().includes(searchVal)) ||
                                  (item.trrName.toLowerCase().includes(searchVal));

            const matchesStatus = selectedStatuses.includes(item.projectStatus);
            const type = item.engagementType || 'Opportunity';
            const matchesType = selectedTypes.includes(type);

            if (quickFilterMode === 'pending_outcome' && !isOutcomePending(item)) return false;

            return matchesSearch && matchesStatus && matchesType;
        });
    }

    function refreshActiveView() {
        if(document.getElementById('dashboardView').classList.contains('active')) {
            renderTable();
        } else if (document.getElementById('timelineView').classList.contains('active')) {
            renderGlobalTimeline();
        }
    }

    function formatCurrency(value) {
        if (!value || isNaN(value) || parseFloat(value) === 0) return '-';
        let val = parseFloat(value);
        const sym = (typeof currencySymbol === 'function') ? currencySymbol() : '€';
        if (val >= 1000000) return sym + (val / 1000000).toFixed(1) + 'M';
        if (val >= 1000) return sym + (val / 1000).toFixed(0) + 'k';
        return sym + val.toFixed(0);
    }

function renderSidebarStats() {
  let totalAmount = 0;
  let activeCount = 0;
  let pendingCount = 0;
  const districtCounts = {};

  // "This FY" stats — Opps cerradas dentro del FY actual
  const fyNow = getFYForDate(new Date());
  const fyBounds = getFYBounds(fyNow);
  let fyClosedOpps = 0, fyWonOpps = 0, fyWonAmount = 0;
  const parseAmt = v => parseFloat((v || '0').toString().replace(/[",$\s]/g, '')) || 0;

  trrList.forEach(item => {
    const status = (item.projectStatus || '').trim();
    const type = (item.engagementType || 'Opportunity').trim();

    if (status !== 'Closed') activeCount++;
    if (isOutcomePending(item)) pendingCount++;

    if (status != 'Closed') {
      const cleanAmt = parseAmt(item.oppAmount);
      totalAmount += cleanAmt;
    }

    if (status !== 'Closed') {
      const d = (item.district || '').trim();
      if (d) districtCounts[d] = (districtCounts[d] || 0) + 1;
    }

    // FY stats: sólo Opps cerradas dentro del FY actual
    if (status === 'Closed' && type === 'Opportunity') {
      const closeDateStr = item.closedDate || item.endDate || '';
      const cd = closeDateStr ? new Date(closeDateStr) : null;
      if (cd && !isNaN(cd) && cd >= fyBounds.from && cd <= fyBounds.to) {
        fyClosedOpps++;
        if (item.commercialOutcome === 'Won') {
          fyWonOpps++;
          fyWonAmount += parseAmt(item.finalAmount);
        }
      }
    }
  });

  document.getElementById('kpiTotalAmount').innerText = formatCurrency(totalAmount);
  document.getElementById('kpiTotalCount').innerText = activeCount;

  // Badge de "Outcome Pending" en el sidebar
  const pendingBadge = document.getElementById('pendingBadge');
  if (pendingBadge) {
    if (pendingCount > 0) {
      pendingBadge.textContent = pendingCount;
      pendingBadge.style.display = '';
    } else {
      pendingBadge.style.display = 'none';
    }
  }

  // Widget "This FY" — se muestra si hay al menos alguna Opp cerrada este FY
  const fyWrap = document.getElementById('fyWidgetWrap');
  if (fyWrap) {
    if (fyClosedOpps > 0) {
      fyWrap.style.display = '';
      document.getElementById('fyLabel').textContent = `FY${fyNow}`;
      document.getElementById('fyWonCount').textContent = fyWonOpps;
      document.getElementById('fyClosedCount').textContent = fyClosedOpps;
      const rate = fyClosedOpps > 0 ? Math.round((fyWonOpps / fyClosedOpps) * 100) : 0;
      document.getElementById('fyWinRate').textContent = rate + '%';
      document.getElementById('fyWinBar').style.width = rate + '%';
      document.getElementById('fyWonAmount').textContent = formatCurrency(fyWonAmount) + ' won';
    } else {
      fyWrap.style.display = 'none';
    }
  }

  // Widget TRRs by District — solo se muestra si hay datos
  const wrap = document.getElementById('districtWidgetWrap');
  const list = document.getElementById('districtList');
  if (wrap && list) {
    const entries = Object.entries(districtCounts).sort((a, b) => b[1] - a[1]);
    if (entries.length === 0) {
      wrap.style.display = 'none';
    } else {
      wrap.style.display = '';
      const maxCount = entries[0][1];
      list.innerHTML = entries.map(([name, count]) => {
        const pct = Math.round((count / maxCount) * 100);
        const safeName = String(name).replace(/[<>&"]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c]));
        return `
          <div class="d-flex justify-content-between align-items-center mb-1" style="gap:6px;">
            <span class="text-truncate" title="${safeName}" style="max-width:170px;">${safeName}</span>
            <span class="badge bg-secondary" style="min-width:26px;">${count}</span>
          </div>
          <div style="height:3px; background:#eee; border-radius:2px; margin-bottom:8px;">
            <div style="height:100%; width:${pct}%; background:#6f42c1; border-radius:2px;"></div>
          </div>`;
      }).join('');
    }
  }
}


    // Fields that belong to a TRR. Anything outside this list is stripped on import
    // to prevent leaking settings (compensation plan, FY, snapshots, currency) across users.
    const TRR_ALLOWED_FIELDS = new Set([
        'id','trrName','creationDate','accountName','ownerName','district',
        'cortexProduct','engagementType','oppAmount','projectStatus',
        'sfdcTrrLink','sfdcOppLink','sfdcTechValLink',
        'startDate','endDate','complexity','workload',
        'progress','nextSteps','challenges','comments',
        'technicalOutcome','commercialOutcome','finalAmount','renewAmount',
        'lossReason','closedDate',
    ]);
    function sanitizeTrr(obj) {
        if (!obj || typeof obj !== 'object') return null;
        const clean = {};
        for (const k of Object.keys(obj)) {
            if (TRR_ALLOWED_FIELDS.has(k)) clean[k] = obj[k];
        }
        return (clean.id || clean.trrName || clean.accountName) ? clean : null;
    }

    function exportData(e) {
        if(e) e.preventDefault();
        if (!trrList || trrList.length === 0) { alert("No data to export."); return; }
        // Whitelist: only TRR fields. Compensation Plan, FY setting and snapshots
        // are intentionally NOT part of the export (they are personal settings).
        const safe = trrList.map(sanitizeTrr).filter(Boolean);
        const dataStr = JSON.stringify(safe, null, 2);
        const blob = new Blob([dataStr], { type: "application/json" });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `PoV_Radar_${new Date().toISOString().split('T')[0]}.json`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    function importData(input) {
        const files = input.files;
        if (files.length === 0) return;
        if (!confirm("Merge Data?\nOK: Merge\nCancel: Replace All")) trrList = [];
        let processedCount = 0;
        Array.from(files).forEach(file => {
            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    // Accept plain arrays OR wrapped {items:[...]} — anything else is ignored.
                    // Never touches settings/FY/compensation: only trrList is mutated.
                    const parsed = JSON.parse(e.target.result);
                    const importedData = Array.isArray(parsed) ? parsed
                                        : (parsed && Array.isArray(parsed.items) ? parsed.items : null);
                    if (importedData) {
                        importedData.forEach(raw => {
                            const item = sanitizeTrr(raw);
                            if (!item) return;
                            const existingIndex = trrList.findIndex(t => t.id === item.id);
                            if (existingIndex >= 0) trrList[existingIndex] = item;
                            else trrList.push(item);
                        });
                    }
                } catch (err) { alert("Invalid JSON."); }
                processedCount++;
                if (processedCount === files.length) {
                    saveToStorage();
                    input.value = '';
                    refreshActiveView();
                }
            };
            reader.readAsText(file);
        });
    }

    function getNext4Weeks() {
        const weeks = [];
        let current = new Date();
        const day = current.getDay();
        const diff = current.getDate() - day + (day === 0 ? -6 : 1);
        current.setDate(diff); current.setHours(0,0,0,0);
        for(let i=0; i<4; i++) {
            const start = new Date(current);
            const end = new Date(start);
            end.setDate(start.getDate() + 6);
            end.setHours(23,59,59,999);
            weeks.push({start, end});
            current.setDate(current.getDate() + 7);
        }
        return weeks;
    }

    // Cache de la data de TimeTracker (para no golpear el endpoint en cada render)
    let ttData = null;
    async function fetchTimeTrackerData() {
        try {
            const r = await fetch('timetracker/?action=summary', {cache: 'no-store'});
            if (!r.ok) return null;
            const j = await r.json();
            return j.ok ? j : null;
        } catch(e) { return null; }
    }
    function fmtHm(sec) {
        const sign = sec < 0 ? '-' : '';
        sec = Math.abs(sec|0);
        const h = Math.floor(sec/3600);
        const m = Math.floor((sec%3600)/60);
        return `${sign}${h}h${m>0?' '+String(m).padStart(2,'0')+'m':''}`;
    }
    function ymdLocal(d) {
        return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
    }

    // Snapshots del forecast — clave: lunes YYYY-MM-DD → { owner: {points, count} }
    const SNAP_KEY = 'pov_radar_forecast_snapshots';
    const SNAP_KEEP_WEEKS = 12;
    function loadSnapshots() {
        try { return JSON.parse(localStorage.getItem(SNAP_KEY) || '{}') || {}; }
        catch(e) { return {}; }
    }
    function saveSnapshot(mondayKey, ownersData) {
        const snaps = loadSnapshots();
        snaps[mondayKey] = ownersData;
        // Podar: conservar solo los N lunes más recientes
        const keys = Object.keys(snaps).sort().reverse().slice(0, SNAP_KEEP_WEEKS);
        const pruned = {};
        keys.forEach(k => pruned[k] = snaps[k]);
        localStorage.setItem(SNAP_KEY, JSON.stringify(pruned));
    }
    function getSnapshotForWeek(mondayKey) {
        return loadSnapshots()[mondayKey] || null;
    }

    async function renderForecast() {
        const tbody = document.getElementById('forecastBody');
        const headerRow = document.getElementById('forecastHeader');
        if(!tbody || !headerRow) return;
        // Refresh TT data (silencioso si falla)
        ttData = await fetchTimeTrackerData();
        const ttUser = (ttData && ttData.user_name || '').toLowerCase().trim();

        const weeks = getNext4Weeks();
        headerRow.innerHTML = '<th class="text-start ps-3" style="width: 20%;">Owner</th>';
        // Columna retrospectiva "Last Week" — siempre visible (snapshot histórico + horas TT)
        {
            const lm = new Date(weeks[0].start); lm.setDate(lm.getDate() - 7);
            const dateStr = `${lm.getDate()}/${lm.getMonth()+1}`;
            headerRow.innerHTML += `<th class="text-muted" style="background:#f8f9fa;" title="Snapshot del forecast que había el lunes de esa semana + horas reales">Last Week <br><small class="fw-normal">${dateStr}</small></th>`;
        }
        const weekLabels = ["This Week", "Next Week", "Week +2", "Week +3"];
        weeks.forEach((w, idx) => {
            const dateStr = `${w.start.getDate()}/${w.start.getMonth()+1}`;
            headerRow.innerHTML += `<th>${weekLabels[idx]} <br><small class="fw-normal text-muted">${dateStr}</small></th>`;
        });
        const owners = [...new Set(trrList.map(i => i.ownerName || 'Unassigned'))].sort();
        // La columna "Last Week" se muestra siempre (para poder ver snapshots aunque TT no responda)
        const hasLastWeek = true;
        const totalCols = 1 + weeks.length + (hasLastWeek ? 1 : 0);
        if(owners.length === 0) { tbody.innerHTML = `<tr><td colspan="${totalCols}" class="text-center py-3">No active data</td></tr>`; return; }

        // Score helpers (extraídos para no duplicar en snapshot y render)
        const compScore = { 'Low': 1, 'Medium': 2, 'High': 4 };
        const workScore = { 'Light': 0, 'Normal': 1, 'Heavy': 3 };
        function pointsForOwnerWeek(owner, week) {
            let points = 0, count = 0;
            trrList.forEach(item => {
                if ((item.ownerName || 'Unassigned') !== owner) return;
                if (['Parked', 'Not Started', 'Closed'].includes(item.projectStatus)) return;
                const pStart = new Date(item.startDate);
                const pEnd = new Date(item.endDate);
                if (pStart <= week.end && pEnd >= week.start) {
                    const cVal = compScore[item.complexity] || 2;
                    const wVal = (workScore[item.workload] !== undefined) ? workScore[item.workload] : 1;
                    points += (cVal + wVal);
                    count++;
                }
            });
            return {points, count};
        }
        function badgeStyleForPoints(points) {
            let icon = '💤', bgStyle = 'background-color: #f8f9fa; color: #6c757d; border: 1px solid #dee2e6;';
            if (points >= 39) { icon = '🔥'; bgStyle = 'background-color: #dc3545; color: white;'; }
            else if (points >= 26) { icon = '🥵'; bgStyle = 'background-color: #fd7e14; color: white;'; }
            else if (points >= 16) { icon = '🟡'; bgStyle = 'background-color: #ffc107; color: #212529;'; }
            else if (points >= 6) { icon = '🟢'; bgStyle = 'background-color: #d1e7dd; color: #0f5132; border: 1px solid #badbcc;'; }
            return {icon, bgStyle};
        }

        // Snapshot "this week" para todos los owners → futura columna "Last Week"
        const thisMondayKey = ymdLocal(weeks[0].start);
        const snapshotThisWeek = {};
        owners.forEach(o => { snapshotThisWeek[o] = pointsForOwnerWeek(o, weeks[0]); });
        saveSnapshot(thisMondayKey, snapshotThisWeek);

        // Snapshot de la semana pasada (si existe)
        const lastMonday = new Date(weeks[0].start); lastMonday.setDate(lastMonday.getDate() - 7);
        const lastWeekSnap = getSnapshotForWeek(ymdLocal(lastMonday));

        tbody.innerHTML = '';
        owners.forEach(owner => {
            const isMe = ttUser && owner.toLowerCase().trim() === ttUser;
            // Badge del banco de horas junto al nombre, solo si es el usuario y TT lo tiene
            let ownerCell = `<td class="fw-bold ps-3">${owner}`;
            if (isMe && ttData && ttData.balance) {
                const bs = ttData.balance.balance_sec;
                const cls = bs >= 0 ? 'bg-success' : (bs < -8*3600 ? 'bg-danger' : 'bg-warning text-dark');
                ownerCell += ` <span class="badge ${cls} ms-1" title="Banco de horas TimeTracker" style="font-size:.65rem;">Banco ${bs>=0?'+':'-'}${fmtHm(Math.abs(bs))}</span>`;
            }
            ownerCell += `</td>`;
            let rowHtml = `<tr>${ownerCell}`;

            // ── Celda LAST WEEK ──
            // Combina: puntos del snapshot (planificado que se guardó ese lunes) + horas reales TT (solo para el user)
            let lwSnap = lastWeekSnap ? lastWeekSnap[owner] : null;
            let lwBadge = '';
            if (lwSnap && lwSnap.count > 0) {
                const {icon, bgStyle} = badgeStyleForPoints(lwSnap.points);
                lwBadge = `<div class="forecast-badge" style="${bgStyle}; opacity:.85;" title="Planificado que había ese lunes"><span class="me-2 fs-6">${icon}</span><span><strong>${lwSnap.points} pts</strong> <small>(${lwSnap.count})</small></span></div>`;
            }
            let lwHours = '';
            if (isMe && ttData && ttData.week_previous) {
                const pw = ttData.week_previous;
                const pct = pw.target_sec > 0 ? Math.round(pw.total_sec / pw.target_sec * 100) : 0;
                const cls = pct >= 110 ? 'text-danger' : (pct >= 90 ? 'text-success' : (pct >= 60 ? 'text-warning' : 'text-muted'));
                lwHours = `<div class="small mt-1 ${cls}" style="font-weight:600;" title="Horas reales de TimeTracker">⏱ ${fmtHm(pw.total_sec)} / ${fmtHm(pw.target_sec)} · ${pct}%</div>`;
            }
            if (lwBadge || lwHours) {
                rowHtml += `<td class="forecast-cell" style="background:#f8f9fa;">${lwBadge}${lwHours}</td>`;
            } else {
                rowHtml += '<td class="forecast-cell text-muted" style="background:#f8f9fa;">—</td>';
            }

            // ── Celdas de las 4 semanas ──
            weeks.forEach((week, weekIdx) => {
                const {points, count} = weekIdx === 0 ? snapshotThisWeek[owner] : pointsForOwnerWeek(owner, week);
                const {icon, bgStyle} = badgeStyleForPoints(points);

                // Overlay TimeTracker en "This Week" para el usuario
                let ttOverlay = '';
                let cellBorder = '';
                if (isMe && weekIdx === 0 && ttData && ttData.week) {
                    const wSec = ttData.week.total_sec;
                    const wTgt = ttData.week.target_sec || 1;
                    const pct  = Math.round(wSec / wTgt * 100);
                    ttOverlay = `<div class="small mt-1" style="font-weight:500;">⏱ ${fmtHm(wSec)} / ${fmtHm(wTgt)} · ${pct}%</div>`;
                    if (pct >= 110) cellBorder = 'box-shadow: 0 0 0 3px #dc3545 inset; border-radius: 8px;';
                    else if (pct >= 90) cellBorder = 'box-shadow: 0 0 0 3px #198754 inset; border-radius: 8px;';
                    else if (pct >= 60) cellBorder = 'box-shadow: 0 0 0 3px #ffc107 inset; border-radius: 8px;';
                }
                const cellStyle = cellBorder ? ` style="${cellBorder}"` : '';
                if (points === 0) rowHtml += `<td class="forecast-cell"${cellStyle}><div class="forecast-badge" style="${bgStyle}">${icon} Free</div>${ttOverlay}</td>`;
                else rowHtml += `<td class="forecast-cell"${cellStyle}><div class="forecast-badge" style="${bgStyle}"><span class="me-2 fs-6">${icon}</span><span><strong>${points} pts</strong> <small>(${count})</small></span></div>${ttOverlay}</td>`;
            });
            rowHtml += '</tr>';
            tbody.innerHTML += rowHtml;
        });
    }

    // --- CRUD ---
    function saveTRR(e) {
        e.preventDefault();
        const currentOwnerInput = document.getElementById('ownerName').value.trim();
        if(currentOwnerInput && currentOwnerInput !== defaultOwner) {
            defaultOwner = currentOwnerInput;
            localStorage.setItem('pov_radar_default_owner', defaultOwner);
        }
        const selectedOptions = Array.from(document.getElementById('cortexProduct').selectedOptions).map(opt => opt.value);
        const lossReasonRaw = document.getElementById('lossReason').value;
        const lossReasonFinal = lossReasonRaw === 'Other'
            ? ('Other: ' + (document.getElementById('lossReasonOther').value || '').trim())
            : lossReasonRaw;
        const trrData = {
            id: document.getElementById('trrId').value || Date.now().toString(),
            trrName: document.getElementById('trrName').value,
            creationDate: document.getElementById('creationDate').value,
            accountName: document.getElementById('accountName').value,
            ownerName: currentOwnerInput || 'Unassigned',
            district: document.getElementById('district').value.trim(),
            cortexProduct: selectedOptions.join(', '),

            engagementType: document.getElementById('engagementType').value,
            oppAmount: document.getElementById('oppAmount').value,

            projectStatus: document.getElementById('projectStatus').value,
            sfdcTrrLink: document.getElementById('sfdcTrrLink').value,
            sfdcOppLink: document.getElementById('sfdcOppLink').value,
            sfdcTechValLink: document.getElementById('sfdcTechValLink').value,
            startDate: document.getElementById('startDate').value,
            endDate: document.getElementById('endDate').value,
            complexity: document.getElementById('complexity').value,
            workload: document.getElementById('workload').value,
            progress: document.getElementById('progress').value,
            nextSteps: document.getElementById('nextSteps').value,
            challenges: document.getElementById('challenges').value,
            comments: document.getElementById('comments').value,

            // Nuevos campos de outcome
            technicalOutcome:   document.getElementById('technicalOutcome').value || '',
            commercialOutcome:  document.getElementById('commercialOutcome').value || '',
            finalAmount:        document.getElementById('finalAmount').value || '',
            renewAmount:        document.getElementById('renewAmount').value || '',
            lossReason:         lossReasonFinal || '',
            closedDate:         document.getElementById('closedDate').value || '',
        };

        // Validación obligatoria al cerrar
        if (trrData.projectStatus === 'Closed') {
            const isOpp = trrData.engagementType === 'Opportunity';
            const errors = [];
            if (!trrData.closedDate) errors.push('Actual Close Date is required to close.');
            if (!trrData.technicalOutcome) errors.push('Technical Outcome is required to close.');
            if (isOpp) {
                if (!trrData.commercialOutcome) errors.push('Commercial Outcome is required to close an Opportunity.');
                if (trrData.commercialOutcome === 'Won' && !(parseFloat(trrData.finalAmount) > 0)) errors.push('Final Amount is required and must be > 0 for a Won Opportunity.');
                if (trrData.commercialOutcome === 'Won') {
                    const fa = parseFloat(trrData.finalAmount) || 0;
                    const ra = parseFloat(trrData.renewAmount) || 0;
                    if (ra < 0) errors.push('Renew Amount cannot be negative.');
                    if (ra > fa) errors.push('Renew Amount cannot exceed Final Amount.');
                }
            }
            if (errors.length) {
                alert('⚠ Cannot close TRR:\n\n' + errors.join('\n'));
                return;
            }
            // endDate (Est. End Date) NO se toca — es la estimación original del usuario.
        }

        const existingIndex = trrList.findIndex(t => t.id === trrData.id);
        if (existingIndex >= 0) trrList[existingIndex] = trrData;
        else trrList.push(trrData);
        saveToStorage();
        showDashboard();
    }

    function deleteTRR(id) {
        if(confirm('Delete this TRR?')) {
            trrList = trrList.filter(t => t.id !== id);
            saveToStorage();
        }
    }

    function editTRR(id) {
        const item = trrList.find(t => t.id === id);
        if(!item) return;
        document.getElementById('trrId').value = item.id;
        document.getElementById('trrName').value = item.trrName;
        document.getElementById('creationDate').value = item.creationDate;
        document.getElementById('accountName').value = item.accountName;
        document.getElementById('ownerName').value = item.ownerName || '';
        document.getElementById('district').value = item.district || '';
        const products = (item.cortexProduct || '').split(', ');
        const select = document.getElementById('cortexProduct');
        Array.from(select.options).forEach(opt => opt.selected = products.includes(opt.value));

        document.getElementById('engagementType').value = item.engagementType || 'Opportunity';
        document.getElementById('oppAmount').value = item.oppAmount || ''; 

        document.getElementById('projectStatus').value = item.projectStatus;
        document.getElementById('sfdcTrrLink').value = item.sfdcTrrLink || '';
        document.getElementById('sfdcOppLink').value = item.sfdcOppLink || '';
        document.getElementById('sfdcTechValLink').value = item.sfdcTechValLink || '';
        document.getElementById('startDate').value = item.startDate || '';
        document.getElementById('endDate').value = item.endDate || '';
        document.getElementById('complexity').value = item.complexity || 'Medium';
        document.getElementById('workload').value = item.workload || 'Normal';
        document.getElementById('progress').value = item.progress || '';
        document.getElementById('nextSteps').value = item.nextSteps || '';
        document.getElementById('challenges').value = item.challenges || '';
        document.getElementById('comments').value = item.comments || '';

        // Load outcome fields
        document.getElementById('technicalOutcome').value  = item.technicalOutcome  || '';
        document.getElementById('commercialOutcome').value = item.commercialOutcome || '';
        document.getElementById('finalAmount').value       = item.finalAmount       || '';
        document.getElementById('renewAmount').value       = item.renewAmount       || 0;
        document.getElementById('closedDate').value        = item.closedDate        || '';
        // Loss reason may have been stored as "Other: ..." — split back
        const lrRaw = item.lossReason || '';
        if (lrRaw.startsWith('Other:')) {
            document.getElementById('lossReason').value = 'Other';
            document.getElementById('lossReasonOther').value = lrRaw.slice(6).trim();
            document.getElementById('lossReasonOther').style.display = '';
        } else {
            document.getElementById('lossReason').value = lrRaw;
            document.getElementById('lossReasonOther').value = '';
            document.getElementById('lossReasonOther').style.display = 'none';
        }

        document.getElementById('formTitle').innerText = 'Edit Report: ' + item.trrName;
        showCreateForm(false); 
    }

    function viewTRR(id) {
        const item = trrList.find(t => t.id === id);
        if(!item) return;
        const modalBody = document.getElementById('viewModalBody');
        const color = getWeightedColor(item.complexity, item.workload);
        const productsHtml = (item.cortexProduct || 'N/A').split(', ').map(p => `<span class="badge bg-dark me-1">${p}</span>`).join('');
        let linksHtml = '';
        if(item.sfdcTrrLink) linksHtml += `<a href="${item.sfdcTrrLink}" target="_blank" class="btn btn-sm btn-outline-primary me-1 mb-1"><i class="fas fa-link"></i> TRR</a>`;
        if(item.sfdcOppLink) linksHtml += `<a href="${item.sfdcOppLink}" target="_blank" class="btn btn-sm btn-outline-success me-1 mb-1"><i class="fas fa-hand-holding-usd"></i> Opp</a>`;
        if(item.sfdcTechValLink) linksHtml += `<a href="${item.sfdcTechValLink}" target="_blank" class="btn btn-sm btn-outline-info mb-1"><i class="fas fa-clipboard-check"></i> Tech Val</a>`;
        let engBadge = '<span class="badge badge-opp">Opportunity</span>';
        if(item.engagementType === 'Post Sales') engBadge = '<span class="badge badge-post">Post Sales</span>';
        if(item.engagementType === 'Events') engBadge = '<span class="badge badge-event">Event</span>';

        const cleanAmt = item.oppAmount ? parseFloat(item.oppAmount.toString().replace(/[",$\s]/g, '')) : 0;
        const finalAmt = parseFloat(item.finalAmount) || 0;
        let amountDisplay = '';
        if (item.projectStatus === 'Closed' && item.commercialOutcome === 'Won' && finalAmt > 0) {
            amountDisplay = `<h3 class="text-success fw-bold">${formatCurrency(finalAmt)} <small class="text-muted" style="font-size:.6em;">final</small></h3>`;
        } else if (cleanAmt > 0) {
            amountDisplay = `<h3 class="text-success fw-bold">${formatCurrency(cleanAmt)}</h3>`;
        }
        const outcomeLine = outcomeBadge(item);
        const lossLine = item.lossReason ? `<div class="small text-muted mt-1"><i class="fas fa-info-circle"></i> Loss reason: ${item.lossReason}</div>` : '';

        modalBody.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
                <h4>${item.trrName} ${engBadge} <small class="text-muted">| ${item.accountName}</small></h4>
            </div>
            <div class="mb-3">
                ${productsHtml}
                <span class="badge bg-light text-dark border ms-1">Owner: ${item.ownerName || 'N/A'}</span>
                ${item.district ? `<span class="badge bg-light text-dark border ms-1"><i class="fas fa-map-marker-alt me-1 text-muted"></i>${item.district}</span>` : ''}
            </div>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>${amountDisplay}</div>
                <div class="d-flex gap-2">
                    <span class="badge ${getStatusClass(item.projectStatus)} align-self-center" style="font-size:1rem">${item.projectStatus}</span>
                    ${linksHtml}
                </div>
            </div>
            ${outcomeLine ? `<div class="mb-2">${outcomeLine}${lossLine}</div>` : ''}
            <hr>
            <div class="row">
                <div class="col-6"><strong>Start:</strong> ${item.startDate || 'N/A'}</div>
                <div class="col-6"><strong>End:</strong> ${item.endDate || 'N/A'}</div>
                <div class="col-12 mt-2">
                    <strong>Effort Calc:</strong> 
                    <span class="badge" style="background-color:${color}; color:white">${item.complexity} + ${item.workload}</span>
                </div>
            </div>
            <hr>
            <h6>Progress</h6><p class="text-muted text-break">${item.progress || 'No info'}</p>
            <h6>Next Steps</h6><p class="text-muted text-break">${item.nextSteps || 'No info'}</p>
            <h6 class="text-danger">Challenges</h6><p class="text-muted text-break">${item.challenges || 'None'}</p>
            <hr><small><i>Last Updated: ${item.creationDate}</i></small>
        `;
        new bootstrap.Modal(document.getElementById('viewModal')).show();
    }

    function getStatusClass(status) {
        if(status === 'On Track') return 'status-on-track';
        if(status === 'At Risk') return 'status-at-risk';
        if(status === 'Planned') return 'status-planned';
        if(status === 'Not Started') return 'status-not-started';
        if(status === 'Parked') return 'status-parked';
        if(status === 'Closed') return 'status-closed';
        return 'bg-secondary text-white';
    }

    function getWeightedColor(complexity, workload) {
        const compScore = { 'Low': 1, 'Medium': 2, 'High': 4 };
        const workScore = { 'Light': 0, 'Normal': 1, 'Heavy': 3 };
        const cVal = compScore[complexity] || 2;
        const wVal = (workScore[workload] !== undefined) ? workScore[workload] : 1; 
        const total = cVal + wVal;
        if (total >= 7) return '#dc3545';
        if (total === 5) return '#fd7e14';
        if (total === 3) return '#ffc107';
        return '#198754';
    }

    function formatAccountName(name) {
        if (!name) return 'N/A';
        return name.length > 30 ? name.substring(0, 30) + '...' : name; 
    }

    function renderTable() {
        renderForecast();
        const tbody = document.getElementById('trrTableBody');
        const emptyState = document.getElementById('emptyState');
        tbody.innerHTML = '';
        const filteredList = getFilteredData();

        if (filteredList.length === 0) { emptyState.style.display = 'block'; return; } 
        else { emptyState.style.display = 'none'; }

        // SORT LOGIC
        if (currentSort === 'amount') {
             filteredList.sort((a, b) => {
                 const valA = parseFloat((a.oppAmount || '0').toString().replace(/[",$\s]/g, ''));
                 const valB = parseFloat((b.oppAmount || '0').toString().replace(/[",$\s]/g, ''));
                 return valB - valA;
             });
        } else {
             filteredList.sort((a, b) => new Date(b.creationDate) - new Date(a.creationDate));
        }
        
        filteredList.forEach(item => {
            const statusClass = getStatusClass(item.projectStatus);
            let timelineText = '<small class="text-muted">No dates</small>';
            let effortBadge = '';

            if(item.startDate && item.endDate) {
                const start = new Date(item.startDate);
                const end = new Date(item.endDate);
                const diffDays = Math.ceil(Math.abs(end - start) / (1000 * 60 * 60 * 24)); 
                timelineText = `<small>${item.startDate} <i class="fas fa-arrow-right"></i> ${item.endDate} (${diffDays}d)</small>`;
                const color = getWeightedColor(item.complexity, item.workload);
                let effortLabel = color === '#198754' ? 'Low' : (color === '#ffc107' ? 'Mod' : (color === '#fd7e14' ? 'High' : 'Crit'));
                effortBadge = `<span class="badge" style="background-color:${color}; font-size:0.7rem">${effortLabel}</span>`;
            }

            let typeBadge = '';
            const eType = item.engagementType || 'Opportunity';
            if (eType === 'Post Sales') typeBadge = '<span class="badge badge-post ms-1" style="font-size:0.65rem">Post Sales</span>';
            else if (eType === 'Events') typeBadge = '<span class="badge badge-event ms-1" style="font-size:0.65rem">Event</span>';
            else typeBadge = '<span class="badge badge-opp ms-1" style="font-size:0.65rem">Opp</span>';

            // Amount Parsing for Table
            const cleanAmt = item.oppAmount ? parseFloat(item.oppAmount.toString().replace(/[",$\s]/g, '')) : 0;
            const amountText = cleanAmt > 0 ? `<div class="amount-text">${formatCurrency(cleanAmt)}</div>` : '<div class="text-muted small">-</div>';

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>
                    <div class="d-flex align-items-center mb-1">
                        <strong>${item.trrName}</strong>
                        ${typeBadge}
                    </div>
                    <div class="small text-primary text-truncate-cell" title="${item.cortexProduct}" style="max-width:200px">${item.cortexProduct || 'Unknown'}</div>
                    <div class="small text-muted"><i class="fas fa-user"></i> ${item.ownerName || 'Unassigned'}</div>
                </td>
                <td><div class="fw-bold text-truncate-cell" title="${item.accountName}">${formatAccountName(item.accountName)}</div></td>
                <td>${amountText}</td>
                <td>
                    <span class="status-badge ${statusClass}">${item.projectStatus}</span>
                    <div class="mt-1" style="font-size:.65rem; line-height:1.4;">${outcomeBadge(item)}</div>
                </td>
                <td>${timelineText} ${effortBadge}</td>
                <td style="max-width: 250px;">
                    <div class="text-truncate-cell" title="${item.progress || ''}">${item.progress}</div>
                </td>
                <td>
                    <div class="btn-group">
                        <button class="btn btn-sm btn-outline-primary" onclick="viewTRR('${item.id}')"><i class="fas fa-eye"></i></button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="editTRR('${item.id}')"><i class="fas fa-pen"></i></button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteTRR('${item.id}')"><i class="fas fa-trash"></i></button>
                    </div>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

    function renderGlobalTimeline() {
        const chartDiv = document.querySelector("#timelineChart");
        const emptyDiv = document.querySelector("#timelineEmpty");
        if(!chartDiv) return;
        const filteredList = getFilteredData().filter(i => i.startDate && i.endDate && i.projectStatus !== 'Closed');
        if (filteredList.length === 0) { chartDiv.style.display = 'none'; emptyDiv.style.display = 'block'; return; }
        chartDiv.style.display = 'block'; emptyDiv.style.display = 'none';
        if (chartInstance) chartInstance.destroy();

        filteredList.sort((a, b) => {
            const nameA = (a.ownerName || 'Z').toUpperCase();
            const nameB = (b.ownerName || 'Z').toUpperCase();
            if (nameA < nameB) return -1;
            if (nameA > nameB) return 1;
            return new Date(a.startDate) - new Date(b.startDate);
        });

        const seriesData = [];
        const gridRowColors = [];
        let lastOwner = null;
        let colorToggle = false;

        filteredList.forEach((item, index) => {
            const currentOwner = item.ownerName || 'Unassigned';
            if (currentOwner !== lastOwner) {
                colorToggle = !colorToggle;
                lastOwner = currentOwner;
                seriesData.push({
                    x: `${currentOwner}|HEADER`,
                    y: [new Date(item.startDate).getTime(), new Date(item.startDate).getTime()],
                    fillColor: 'transparent', isHeader: true, ownerName: currentOwner, realId: null 
                });
                gridRowColors.push(colorToggle ? '#ffffff' : '#f8f9fa');
            }
            gridRowColors.push(colorToggle ? '#ffffff' : '#f8f9fa');
            const barColor = getWeightedColor(item.complexity, item.workload);
            const cleanAccountName = formatAccountName(item.accountName).replace(/\|/g, '-'); 
            const uniqueLabel = `${currentOwner}|${cleanAccountName}|${index}`; 
            
            // Para closed TRRs usamos closedDate (fecha real de cierre), no la estimación.
            // Para activos usamos endDate (planificado). Fallback cruzado si falta uno u otro.
            const barEndDate = trrEndDate(item);
            seriesData.push({
                x: uniqueLabel,
                y: [new Date(item.startDate).getTime(), new Date(barEndDate).getTime()],
                fillColor: barColor, isHeader: false, trrName: item.trrName, ownerName: currentOwner,
                account: item.accountName, product: item.cortexProduct, complexity: item.complexity, workload: item.workload,
                realId: item.id, barLabel: item.cortexProduct || 'Unknown'
            });
        });

        const options = {
            series: [{ name: 'Projects', data: seriesData }],
            chart: {
                height: (seriesData.length * 35) + 120, 
                type: 'rangeBar',
                toolbar: { show: true },
                fontFamily: 'Segoe UI, sans-serif',
                animations: { enabled: false },
                events: {
                    dataPointSelection: function(event, chartContext, config) {
                        const dataPoint = config.w.config.series[0].data[config.dataPointIndex];
                        if (dataPoint && !dataPoint.isHeader && dataPoint.realId) viewTRR(dataPoint.realId);
                    }
                }
            },
            plotOptions: { bar: { horizontal: true, barHeight: '70%', rangeBarGroupRows: false, dataLabels: { position: 'center' } } },
            dataLabels: {
                enabled: true, textAnchor: 'start',
                formatter: function(val, opt) {
                    const data = opt.w.config.series[0].data[opt.dataPointIndex];
                    return data.isHeader ? "" : data.barLabel; 
                },
                style: { colors: ['#333'], fontSize: '11px', fontWeight: 'bold' },
                dropShadow: { enabled: false } 
            },
            xaxis: { type: 'datetime', position: 'top' },
            yaxis: {
                labels: {
                    style: { fontSize: '13px', colors: seriesData.map(d => d.isHeader ? '#000' : '#555'), fontWeight: seriesData.map(d => d.isHeader ? 700 : 400) },
                    align: 'left', minWidth: 150, maxWidth: 400,
                    formatter: function(val) {
                        if (!val || typeof val !== 'string') return val;
                        const parts = val.split('|');
                        return parts[1] === 'HEADER' ? parts[0] : `\u00A0\u00A0\u00A0\u00A0\u21B3 ${parts[1]}`;
                    }
                }
            },
grid: {
  padding: { top: 60, right: 15, left: 15 }, // sube de 10 a 60 (o 80)
  xaxis: { lines: { show: true } },
  yaxis: { lines: { show: false } },
  row: { colors: gridRowColors, opacity: 1 }
},
yaxis: {
  labels: {
    offsetX: 10,            // empuja el texto hacia la derecha
    align: 'left',
    minWidth: 200,          // opcional: más espacio reservado
    maxWidth: 500,
    formatter: function(val) {
      if (!val || typeof val !== 'string') return val;
      const parts = val.split('|');
      return parts[1] === 'HEADER' ? parts[0] : `\u00A0\u00A0\u00A0\u00A0\u21B3 ${parts[1]}`;
    }
  }
},
            tooltip: {
                custom: function({series, seriesIndex, dataPointIndex, w}) {
                    const data = w.config.series[seriesIndex].data[dataPointIndex];
                    if (data.isHeader) return '';
                    const start = new Date(data.y[0]).toLocaleDateString();
                    const end = new Date(data.y[1]).toLocaleDateString();
                    return `<div class="px-3 py-2" style="background: #fff; border: 1px solid #eee; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                            <strong>${data.trrName}</strong><br><span class="text-primary fw-bold small">${data.ownerName}</span><br>
                            <span>${data.account} | ${data.product}</span><br><span class="badge bg-light text-dark border mt-1">Comp: ${data.complexity} | Load: ${data.workload}</span>
                            <hr class="my-1"><small>📅 ${start} - ${end}</small><br><small class="text-muted">Click to view details</small></div>`;
                }, fixed: { enabled: false }
            },
            fill: { type: 'solid', opacity: 1 }, legend: { show: false } 
        };
        chartInstance = new ApexCharts(chartDiv, options);
        chartInstance.render();
    }

    function parseCSVLine(text) {
        let ret = [''], i = 0, p = '', s = true;
        for (let l in text) {
            l = text[l];
            if ('"' === l) { s = !s; if ('"' === p) { ret[i] += '"'; l = '-'; } else if ('' === p) l = '-'; } 
            else if (s && ',' === l) l = ret[++i] = '';
            else ret[i] += l;
            p = l;
        }
        return ret;
    }

function importSFDC(input) {
  const file = input.files[0];
  if (!file) return;

  const reader = new FileReader();
  reader.onload = function (e) {
    const text = e.target.result;

    // ✅ Normaliza CRLF (Windows) para evitar \r en el último campo
    const rows = text.replace(/\r/g, '').split('\n');
    if (rows.length < 2) { alert('CSV vacío o sin filas de datos.'); return; }

    // ── Mapeo de columnas basado en cabecera (robusto ante cambios de columnas en SFDC) ──
    // El CSV tiene dos cabeceras muy similares: "Technical Resource Request Id" (nombre TRRxxx)
    // y "Technical Resource Request ID" (id interno SFDC). Se distinguen por orden de aparición.
    const headerCells = parseCSVLine(rows[0]).map(h => h.replace(/"/g, '').trim().toLowerCase());
    const headerIdx = {};
    headerCells.forEach((h, i) => {
      if (!headerIdx[h]) headerIdx[h] = [];
      headerIdx[h].push(i);
    });
    const findAll = (name) => headerIdx[name.toLowerCase()] || [];
    const findFirst = (...names) => {
      for (const n of names) { const a = findAll(n); if (a.length) return a[0]; }
      return -1;
    };
    const or = (idx, fallback) => (idx >= 0 ? idx : fallback);

    const trrNameOcc = findAll('technical resource request id');
    const iTrrName   = trrNameOcc.length ? trrNameOcc[0] : 0;
    const iSfdcTrrId = trrNameOcc.length >= 2 ? trrNameOcc[1] : or(findFirst('technical resource request id (18 digit)'), 4);
    const iCreated   = or(findFirst('created date'), 1);
    const iAccount   = or(findFirst('account name', 'account'), 2);
    const iTech      = findFirst('all subdomains', 'technology', 'product', 'cortex product'); // opcional
    const iSfdcOpp   = or(findFirst('18 digit opportunity id', 'opportunity id'), 5);
    const iSfdcExt   = or(findFirst('opportunity extension: opportunity extension name', 'opportunity extension name', 'extension name'), 6);
    const iEngType   = or(findFirst('engagement type'), 7);
    const iAmount    = or(findFirst('net opportunity amount', 'opportunity amount', 'amount'), 8);
    const iDistrict  = findFirst('account owner district', 'district'); // opcional
    const iOwner     = or(findFirst('assigned consultant: full name', 'assigned consultant', 'assigned resource', 'owner'), 9);
    const iStatus    = or(findFirst('engagement status', 'status'), 10);
    const iClosedReason = findFirst('closed reason', 'close reason');

    const cell = (row, idx) => (idx >= 0 && idx < row.length) ? (row[idx] || '').replace(/"/g, '').trim() : '';

    const dataRows = rows.slice(1);
    const sfdcMap = new Map();
    const linkBase = 'https://paloaltonetworks.lightning.force.com/lightning/r';

    dataRows.forEach((rowString) => {
      if (!rowString || rowString.trim() === '') return;

      const row = parseCSVLine(rowString);
      if (!row || row.length < 3) return;

      const trrId = cell(row, iTrrName);
      if (!trrId.toUpperCase().startsWith('TRR')) return;

      const engType = cell(row, iEngType);

      let rawAmount = cell(row, iAmount);
      let cleanAmount = rawAmount.replace(/[^0-9.-]+/g, '');
      if (cleanAmount === '' || isNaN(parseFloat(cleanAmount))) cleanAmount = '0';

      // ✅ REGLA: si es Post Sales, fuerza amount = 0
      if (engType === 'Post Sales') cleanAmount = '0';

      // Defensa: si owner viene como "Active"/"Inactive" y status trae un nombre, intercambia
      let owner = cell(row, iOwner);
      let statusRaw = cell(row, iStatus);
      if ((owner === 'Active' || owner === 'Inactive') && statusRaw && statusRaw !== 'Active' && statusRaw !== 'Inactive') {
        [owner, statusRaw] = [statusRaw, owner];
      }

      sfdcMap.set(trrId, {
        createdDate: cell(row, iCreated),
        account: cell(row, iAccount),
        rawTech: cell(row, iTech),
        sfdcTrrId: cell(row, iSfdcTrrId),
        sfdcOppId: cell(row, iSfdcOpp),
        sfdcExtId: cell(row, iSfdcExt),

        engType: engType,
        oppAmount: cleanAmount,
        district: cell(row, iDistrict),
        owner: owner,
        statusRaw: statusRaw,
        closedReason: cell(row, iClosedReason)
      });
    });

    let createdCount = 0;
    let closedCount = 0;
    let updatedCount = 0;
    let manualSkippedCount = 0;

    sfdcMap.forEach((data, trrId) => {
      const existingIndex = trrList.findIndex((t) => t.id === trrId);

      if (existingIndex === -1) {
        // ---- CREATE NEW ----
        let status = 'Not Started';
        if (data.statusRaw === 'Active') status = 'On Track';
        if (data.statusRaw === 'Inactive') status = 'Parked';

        const dateObj = new Date(data.createdDate);
        const isoDate = !isNaN(dateObj) ? dateObj.toISOString().split('T')[0] : '';

        let endDate = '';
        if (isoDate) {
          const endObj = new Date(dateObj);
          endObj.setDate(endObj.getDate() + 30);
          endDate = endObj.toISOString().split('T')[0];
        }

        const techs = (data.rawTech || '')
          .split(';')
          .map((t) => t.trim())
          .filter(Boolean)
          .join(', ');

        const trrLink = data.sfdcTrrId ? `${linkBase}/CE_Request__c/${data.sfdcTrrId}/view` : '';
        const oppLink = data.sfdcOppId ? `${linkBase}/Opportunity/${data.sfdcOppId}/view` : '';
        const techValLink = data.sfdcExtId ? `${linkBase}/Opportunity_Extension__c/${data.sfdcExtId}/view` : '';

        const newItem = {
          id: trrId,
          trrName: trrId,
          creationDate: isoDate,
          accountName: data.account,
          ownerName: data.owner,
          district: data.district || '',
          cortexProduct: techs,

          // ✅ usar el tipo real del CSV
          engagementType: data.engType || 'Opportunity',

          // ✅ amount ya viene forzado a 0 si Post Sales
          oppAmount: data.oppAmount,

          projectStatus: status,
          sfdcTrrLink: trrLink,
          sfdcOppLink: oppLink,
          sfdcTechValLink: techValLink,

          startDate: isoDate,
          endDate: endDate,
          complexity: 'Medium',
          workload: 'Normal',
          progress: 'Imported from SFDC',
          nextSteps: '',
          challenges: '',
          comments: ''
        };

        trrList.push(newItem);
        createdCount++;
      } else {
        // ---- UPDATE EXISTING ----
        const existing = trrList[existingIndex];

        existing.accountName = data.account;
        existing.ownerName = data.owner;
        if (data.district) existing.district = data.district;

        // Solo sobrescribe cortexProduct si el CSV trae dato (evita borrar valor manual del usuario)
        if (data.rawTech && data.rawTech.trim() !== '') {
          existing.cortexProduct = data.rawTech
            .split(';')
            .map((t) => t.trim())
            .filter(Boolean)
            .join(', ');
        }

        if (data.engType) existing.engagementType = data.engType;

        // ✅ Siempre actualiza amount (ya viene 0 si Post Sales)
        existing.oppAmount = data.oppAmount;

        // Refresca links SFDC por si venían mal de imports anteriores rotos
        if (data.sfdcTrrId) existing.sfdcTrrLink = `${linkBase}/CE_Request__c/${data.sfdcTrrId}/view`;
        if (data.sfdcOppId) existing.sfdcOppLink = `${linkBase}/Opportunity/${data.sfdcOppId}/view`;
        if (data.sfdcExtId) existing.sfdcTechValLink = `${linkBase}/Opportunity_Extension__c/${data.sfdcExtId}/view`;

        // Auto-repara projectStatus "Not Started" heredado del import roto anterior
        if (existing.projectStatus === 'Not Started') {
          if (data.statusRaw === 'Active') existing.projectStatus = 'On Track';
          else if (data.statusRaw === 'Inactive') existing.projectStatus = 'Parked';
        }

        updatedCount++;
      }
    });

    // ---- AUTO-CLOSE MISSING TRRs ----
    const autoClosedIds = [];
    trrList.forEach((item) => {
      if (!item.id || !item.id.toUpperCase().startsWith('TRR')) {
        manualSkippedCount++;
        return;
      }
      if (item.projectStatus !== 'Closed' && !sfdcMap.has(item.id)) {
        item.projectStatus = 'Closed';
        // Default de closedDate = hoy (editable en el modal bulk). endDate (estimación) no se toca.
        item.closedDate = getTodayLocalISO();

        const dateStr = new Date().toLocaleDateString();
        item.progress = (item.progress || '') + `\n[${dateStr}] Auto-closed: Missing in SFDC export.`;
        closedCount++;
        autoClosedIds.push(item.id);
      }
    });

    localStorage.setItem('pov_radar_data', JSON.stringify(trrList));
    alert(
      `Sync Complete:\n\n➕ Created: ${createdCount}\n✏️ Updated: ${updatedCount}\n🔒 Auto-Closed: ${closedCount}\n🛡️ Manual Kept: ${manualSkippedCount}`
    );

    input.value = '';
    // Si hubo auto-cierres, abre el modal de outcomes en bloque
    if (autoClosedIds.length > 0) {
      openBulkOutcomeModal(autoClosedIds);
    } else {
      showDashboard();
    }
  };

  reader.readAsText(file);
}
function getTodayLocalISO() {
  const d = new Date();
  const tz = d.getTimezoneOffset() * 60000;
  return new Date(d.getTime() - tz).toISOString().split('T')[0]; // YYYY-MM-DD
}

// ═══════════════════════════════════════════════════════════════════════════
//  FISCAL YEAR SETTINGS
// ═══════════════════════════════════════════════════════════════════════════
const FY_STORAGE_KEY = 'pov_radar_fy_start_month';
const FY_DEFAULT_MONTH = 8;

function getFYStartMonth() {
    const v = parseInt(localStorage.getItem(FY_STORAGE_KEY) || '', 10);
    return (v >= 1 && v <= 12) ? v : FY_DEFAULT_MONTH;
}
function setFYStartMonth(m) {
    m = parseInt(m, 10);
    if (m >= 1 && m <= 12) {
        localStorage.setItem(FY_STORAGE_KEY, String(m));
        return true;
    }
    return false;
}

// Devuelve el número FY para una fecha dada (p.ej. "FY27" para agosto 2026 en año fiscal PA)
function getFYForDate(d) {
    if (!(d instanceof Date)) d = new Date(d);
    const start = getFYStartMonth();
    // Si estamos en el mes de start o posterior → FY del año siguiente
    // (p.ej. Aug 2026 con start=8 → FY27 porque el fiscal year 27 empieza Aug 2026)
    const y = d.getFullYear();
    const fyYear = (d.getMonth() + 1) >= start ? y + 1 : y;
    return fyYear % 100; // "27"
}
function getFYBounds(fyTwoDigit) {
    const start = getFYStartMonth();
    // FY27 empieza el (start) del año (2027 - 1) = 2026
    // Ejemplo con start=8: FY27 = Aug 1, 2026 → Jul 31, 2027
    const startYear = 2000 + fyTwoDigit - 1;
    const from = new Date(startYear, start - 1, 1);
    const to   = new Date(startYear + 1, start - 1, 0, 23, 59, 59); // último día del mes anterior al start
    return {from, to, label: 'FY' + fyTwoDigit};
}
function getCurrentFY() { return getFYForDate(new Date()); }

// ═══════════════════════════════════════════════════════════════════════════
//  COMPENSATION PLAN SETTINGS
// ═══════════════════════════════════════════════════════════════════════════
const COMP_STORAGE_KEY = 'pov_radar_comp_plan';
const COMP_DEFAULTS = {
    base: 0,
    otiPct: 20,
    currency: 'EUR',
    quotaGroup: 0,
    groupSize: 5,
    bcrNew: 1.0,
    bcrRenew: 0.25,
};
const CURRENCY_SYMBOLS = {EUR: '€', USD: '$', GBP: '£'};

function getCompPlan() {
    try {
        const raw = JSON.parse(localStorage.getItem(COMP_STORAGE_KEY) || '{}');
        return {...COMP_DEFAULTS, ...raw};
    } catch(e) { return {...COMP_DEFAULTS}; }
}
function saveCompPlan(p) {
    localStorage.setItem(COMP_STORAGE_KEY, JSON.stringify(p));
}
function currencySymbol() { return CURRENCY_SYMBOLS[getCompPlan().currency] || '€'; }
function individualTarget() {
    const p = getCompPlan();
    const n = Math.max(1, parseInt(p.groupSize) || 1);
    return (parseFloat(p.quotaGroup) || 0) / n;
}

function openCompSettings() {
    const p = getCompPlan();
    document.getElementById('compBase').value        = p.base || '';
    document.getElementById('compOtiPct').value      = p.otiPct;
    document.getElementById('compCurrency').value    = p.currency;
    document.getElementById('compQuotaGroup').value  = p.quotaGroup || '';
    document.getElementById('compGroupSize').value   = p.groupSize;
    document.getElementById('compBcrNew').value      = p.bcrNew;
    document.getElementById('compBcrRenew').value    = p.bcrRenew;
    document.getElementById('compFyMonth').value     = String(getFYStartMonth());
    updateCompDerived();
    ['compQuotaGroup','compGroupSize','compCurrency','compFyMonth'].forEach(id => {
        document.getElementById(id).oninput  = updateCompDerived;
        document.getElementById(id).onchange = updateCompDerived;
    });
    new bootstrap.Modal(document.getElementById('compModal')).show();
}
function updateCompDerived() {
    const cur  = document.getElementById('compCurrency').value || 'EUR';
    const sym  = CURRENCY_SYMBOLS[cur] || '€';
    const base = parseFloat(document.getElementById('compBase').value) || 0;
    const oti  = Math.max(0, Math.min(parseFloat(document.getElementById('compOtiPct').value) || 0, 99));
    const q    = parseFloat(document.getElementById('compQuotaGroup').value) || 0;
    const n    = Math.max(1, parseInt(document.getElementById('compGroupSize').value) || 1);

    const varTarget = oti >= 100 ? 0 : base * oti / (100 - oti);
    const ote       = base + varTarget;
    const fmt = v => sym + ' ' + Number(v).toLocaleString('es-ES', {maximumFractionDigits: 0});

    // Actualiza prefijos de currency dentro del modal
    document.querySelectorAll('.comp-cur-prefix').forEach(el => el.textContent = sym);
    document.getElementById('compVariableTarget').value   = fmt(varTarget);
    document.getElementById('compOte').value              = fmt(ote);
    document.getElementById('compIndividualTarget').value = fmt(q / n);

    // Preview del Fiscal Year — no persiste hasta guardar
    const m = parseInt(document.getElementById('compFyMonth').value, 10);
    const oldM = getFYStartMonth();
    localStorage.setItem(FY_STORAGE_KEY, String(m));
    const cur2 = getFYBounds(getCurrentFY());
    localStorage.setItem(FY_STORAGE_KEY, String(oldM));
    const fmtD = d => d.toISOString().substring(0,10);
    const prev = document.getElementById('compFyPreview');
    if (prev) prev.innerHTML = `<strong>${cur2.label}</strong>: ${fmtD(cur2.from)} → ${fmtD(cur2.to)}`;
}
function saveCompSettings() {
    const plan = {
        base:       parseFloat(document.getElementById('compBase').value) || 0,
        otiPct:     parseFloat(document.getElementById('compOtiPct').value) || 0,
        currency:   document.getElementById('compCurrency').value || 'EUR',
        quotaGroup: parseFloat(document.getElementById('compQuotaGroup').value) || 0,
        groupSize:  parseInt(document.getElementById('compGroupSize').value) || 1,
        bcrNew:     parseFloat(document.getElementById('compBcrNew').value) || 0,
        bcrRenew:   parseFloat(document.getElementById('compBcrRenew').value) || 0,
    };
    saveCompPlan(plan);
    // Persiste el Fiscal Year Month junto con el plan
    setFYStartMonth(document.getElementById('compFyMonth').value);
    // Refresca símbolos en labels del formulario de cierre
    document.querySelectorAll('.currency-label').forEach(el => el.textContent = '(' + (CURRENCY_SYMBOLS[plan.currency] || '€') + ')');
    bootstrap.Modal.getInstance(document.getElementById('compModal')).hide();
    if (document.getElementById('metricsView').classList.contains('active')) renderMetrics();
    if (document.getElementById('dashboardView').classList.contains('active')) refreshActiveView();
    renderSidebarStats();
}

// ═══════════════════════════════════════════════════════════════════════════
//  METRICS DASHBOARD
// ═══════════════════════════════════════════════════════════════════════════
const metricsCharts = {};

function getMetricsDateRange() {
    const mode = document.getElementById('mfDateRange').value;
    const today = new Date();
    let from, to = today, label = '';
    if (mode === 'fy_current') {
        const b = getFYBounds(getCurrentFY());
        from = b.from; to = b.to; label = b.label;
    }
    else if (mode === 'fy_last') {
        const b = getFYBounds(getCurrentFY() - 1);
        from = b.from; to = b.to; label = b.label;
    }
    else if (mode === 'ytd') from = new Date(today.getFullYear(), 0, 1);
    else if (mode === 'q_current') {
        const q = Math.floor(today.getMonth() / 3);
        from = new Date(today.getFullYear(), q * 3, 1);
    }
    else if (mode === 'q_last') {
        const q = Math.floor(today.getMonth() / 3) - 1;
        const y = q < 0 ? today.getFullYear() - 1 : today.getFullYear();
        const qm = ((q % 4) + 4) % 4;
        from = new Date(y, qm * 3, 1);
        to = new Date(y, qm * 3 + 3, 0);
    }
    else if (mode === 'last_90')  from = new Date(today.getTime() - 90 * 86400000);
    else if (mode === 'last_365') from = new Date(today.getTime() - 365 * 86400000);
    else if (mode === 'all')      from = new Date(2000, 0, 1);
    else if (mode === 'custom') {
        const f = document.getElementById('mfFrom').value;
        const t = document.getElementById('mfTo').value;
        from = f ? new Date(f) : new Date(2000, 0, 1);
        to   = t ? new Date(t) : new Date();
    }
    return {from, to, label};
}

function onMetricsFiltersChanged() {
    const isCustom = document.getElementById('mfDateRange').value === 'custom';
    document.getElementById('mfCustomFromWrap').style.display = isCustom ? '' : 'none';
    document.getElementById('mfCustomToWrap').style.display   = isCustom ? '' : 'none';
    renderMetrics();
}

function populateMetricsFilters() {
    // Products
    const prodSel = document.getElementById('mfProduct');
    const currentProd = prodSel.value;
    const products = new Set();
    trrList.forEach(t => (t.cortexProduct || '').split(', ').filter(Boolean).forEach(p => products.add(p)));
    prodSel.innerHTML = '<option value="">All products</option>' +
        [...products].sort().map(p => `<option value="${p}">${p}</option>`).join('');
    prodSel.value = currentProd;
    // Owners
    const ownerSel = document.getElementById('mfOwner');
    const currentOwner = ownerSel.value;
    const owners = new Set();
    trrList.forEach(t => { if (t.ownerName) owners.add(t.ownerName); });
    ownerSel.innerHTML = '<option value="">All owners</option>' +
        [...owners].sort().map(o => `<option value="${o}">${o}</option>`).join('');
    ownerSel.value = currentOwner;
}

function metricsCurrentFilters() {
    const {from, to, label} = getMetricsDateRange();
    return {
        from, to, label,
        product: document.getElementById('mfProduct').value,
        owner:   document.getElementById('mfOwner').value,
    };
}

// Filtra por product/owner. Los KPIs principales trabajan solo con Opps (se aplica dentro).
function metricsFilterTRRs(f) {
    return trrList.filter(t => {
        if (f.product && !((t.cortexProduct || '').split(', ').includes(f.product))) return false;
        if (f.owner && (t.ownerName || 'Unassigned') !== f.owner) return false;
        return true;
    });
}
function inRange(dateStr, from, to) {
    if (!dateStr) return false;
    const d = new Date(dateStr);
    if (isNaN(d)) return false;
    return d >= from && d <= to;
}

// Fecha canónica del "cuándo terminó" un TRR (para agrupar por FY/mes/quarter):
// - Actual Close Date (closedDate) es la fecha que el usuario confirmó al cerrar → fuente de verdad.
// - Fallback a Est. End Date (endDate) solo para TRRs antiguos sin closedDate.
function trrEndDate(t) { return t.closedDate || t.endDate || ''; }

function computeMetrics(filters) {
    // Dashboard principal: SOLO Opportunities (win/loss no aplica a otros tipos)
    const base = metricsFilterTRRs(filters).filter(t => (t.engagementType || 'Opportunity') === 'Opportunity');
    const {from, to} = filters;
    const closed = base.filter(t => t.projectStatus === 'Closed' && inRange(trrEndDate(t), from, to));
    const active = base.filter(t => t.projectStatus !== 'Closed');
    const opps   = closed;

    const won  = opps.filter(t => t.commercialOutcome === 'Won');
    const lost = opps.filter(t => t.commercialOutcome === 'Lost');
    const noDec= opps.filter(t => t.commercialOutcome === 'No Decision');

    const techW  = closed.filter(t => t.technicalOutcome === 'Technical Win');
    const techL  = closed.filter(t => t.technicalOutcome === 'Technical Loss');
    const techP  = closed.filter(t => t.technicalOutcome === 'Partial');

    const sumAmount = (arr, field) => arr.reduce((s, t) => s + (parseFloat((t[field]||'').toString().replace(/[",$\s]/g,'')) || 0), 0);
    const totalWonAmount = sumAmount(won, 'finalAmount');
    const totalLostAmount = sumAmount(lost, 'oppAmount');
    const pipelineActive = sumAmount(base.filter(t => t.projectStatus !== 'Closed' && (t.engagementType||'Opportunity') === 'Opportunity'), 'oppAmount');
    const atRiskAmount   = sumAmount(base.filter(t => t.projectStatus === 'At Risk' && (t.engagementType||'Opportunity') === 'Opportunity'), 'oppAmount');
    const avgDeal = won.length ? totalWonAmount / won.length : 0;

    // Win rates
    const commDenom = won.length + lost.length;
    const winRateComm = commDenom ? (won.length / commDenom) : 0;
    const techDenom = techW.length + techL.length + techP.length;
    const winRateTech = techDenom ? (techW.length / techDenom) : 0;

    // Ciclo medio de venta (Won): días entre startDate y closedDate|endDate
    const cycles = won.map(t => {
        const s = new Date(t.startDate); const e = new Date(trrEndDate(t));
        return (!isNaN(s) && !isNaN(e)) ? (e - s) / 86400000 : null;
    }).filter(x => x !== null && x >= 0);
    const avgCycleDays = cycles.length ? cycles.reduce((a,b) => a+b, 0) / cycles.length : 0;

    // Tasa de abandono: opps que pasaron por Parked antes de cerrarse
    // (Aproximado: buscamos "Parked" en el campo progress de la timeline. Si no, 0.)
    const parkedTouched = opps.filter(t => /parked/i.test(t.progress || '')).length;
    const abandonRate = opps.length ? (parkedTouched / opps.length) : 0;

    // Amount por mes (won)
    const byMonth = {};
    won.forEach(t => {
        const d = new Date(trrEndDate(t));
        if (isNaN(d)) return;
        const key = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`;
        byMonth[key] = (byMonth[key] || 0) + (parseFloat((t.finalAmount||'').toString().replace(/[",$\s]/g,'')) || 0);
    });

    // Win rate por producto
    const byProduct = {};
    opps.forEach(t => {
        (t.cortexProduct || '').split(', ').filter(Boolean).forEach(p => {
            if (!byProduct[p]) byProduct[p] = {won: 0, lost: 0};
            if (t.commercialOutcome === 'Won')  byProduct[p].won++;
            if (t.commercialOutcome === 'Lost') byProduct[p].lost++;
        });
    });

    // Loss reasons
    const lossReasons = {};
    [...lost, ...noDec, ...techL].forEach(t => {
        const r = (t.lossReason || '').replace(/^Other:\s*/i, 'Other: ');
        if (r) lossReasons[r] = (lossReasons[r] || 0) + 1;
    });

    // Matriz Tech × Commercial (solo Opps)
    const matrix = {
        'Technical Win': {Won: 0, Lost: 0, 'No Decision': 0},
        'Partial':       {Won: 0, Lost: 0, 'No Decision': 0},
        'Technical Loss':{Won: 0, Lost: 0, 'No Decision': 0},
    };
    opps.forEach(t => {
        if (matrix[t.technicalOutcome] && matrix[t.technicalOutcome][t.commercialOutcome] !== undefined) {
            matrix[t.technicalOutcome][t.commercialOutcome]++;
        }
    });

    return {
        total: base.length, active: active.length, closed: closed.length,
        won: won.length, lost: lost.length, noDec: noDec.length,
        techW: techW.length, techL: techL.length, techP: techP.length,
        winRateComm, winRateTech,
        totalWonAmount, totalLostAmount, pipelineActive, atRiskAmount, avgDeal,
        avgCycleDays, abandonRate,
        byMonth, byProduct, lossReasons, matrix,
        opps,
    };
}

function computePrevPeriodMetrics(filters) {
    // Ventana anterior del mismo tamaño
    const ms = filters.to - filters.from;
    const prevTo   = new Date(filters.from.getTime() - 1);
    const prevFrom = new Date(prevTo.getTime() - ms);
    return computeMetrics({...filters, from: prevFrom, to: prevTo});
}

function fmtMoney(n) {
    const sym = (typeof currencySymbol === 'function') ? currencySymbol() : '€';
    if (!n || n === 0) return sym + '0';
    if (Math.abs(n) >= 1e6) return sym + (n/1e6).toFixed(1) + 'M';
    if (Math.abs(n) >= 1e3) return sym + (n/1e3).toFixed(1) + 'k';
    return sym + n.toFixed(0);
}
function fmtMoneyFull(n) {
    // Sin sufijos K/M — cifra completa formateada
    const sym = (typeof currencySymbol === 'function') ? currencySymbol() : '€';
    if (!n || isNaN(n)) return sym + ' 0';
    return sym + ' ' + Number(n).toLocaleString('es-ES', {maximumFractionDigits: 0});
}
function fmtPct(x) { return (x * 100).toFixed(1) + '%'; }
function deltaArrow(cur, prev, higherIsBetter = true) {
    if (prev === 0 && cur === 0) return '';
    if (prev === 0) return '<span class="text-success small ms-1">new</span>';
    const diff = cur - prev;
    const pct = (diff / Math.abs(prev)) * 100;
    const good = higherIsBetter ? diff >= 0 : diff <= 0;
    const arrow = diff > 0 ? '▲' : (diff < 0 ? '▼' : '=');
    const cls = good ? 'text-success' : 'text-danger';
    return `<span class="${cls} small ms-1" title="vs periodo anterior">${arrow} ${Math.abs(pct).toFixed(0)}%</span>`;
}

function renderMetricsKPIs(m, prev) {
    const kpi = (label, val, delta = '', color = 'primary', icon = '') => `
        <div class="col-md-3 col-6">
            <div class="card p-3 h-100" style="border-left:4px solid var(--bs-${color});">
                <div class="small text-muted text-uppercase fw-bold" style="font-size:.7rem;letter-spacing:.5px;">${icon ? '<i class="'+icon+' me-1"></i>' : ''}${label}</div>
                <div class="mt-1" style="font-size:1.5rem;font-weight:800;">${val}${delta}</div>
            </div>
        </div>`;
    const box = document.getElementById('metricsKPIs');
    box.innerHTML =
        kpi('Win Rate Commercial', fmtPct(m.winRateComm), deltaArrow(m.winRateComm, prev.winRateComm), 'success', 'fas fa-trophy') +
        kpi('Win Rate Technical',  fmtPct(m.winRateTech), deltaArrow(m.winRateTech, prev.winRateTech), 'info', 'fas fa-wrench') +
        kpi('Total Won',           fmtMoney(m.totalWonAmount), deltaArrow(m.totalWonAmount, prev.totalWonAmount), 'success', 'fas fa-dollar-sign') +
        kpi('Avg Deal (Won)',      fmtMoney(m.avgDeal), deltaArrow(m.avgDeal, prev.avgDeal), 'success', 'fas fa-chart-line') +
        kpi('Pipeline Active',     fmtMoney(m.pipelineActive), deltaArrow(m.pipelineActive, prev.pipelineActive), 'primary', 'fas fa-stream') +
        kpi('Amount at Risk',      fmtMoney(m.atRiskAmount), deltaArrow(m.atRiskAmount, prev.atRiskAmount, false), 'warning', 'fas fa-exclamation-triangle') +
        kpi('Avg Sales Cycle',     m.avgCycleDays > 0 ? Math.round(m.avgCycleDays) + 'd' : '—', deltaArrow(m.avgCycleDays, prev.avgCycleDays, false), 'secondary', 'fas fa-clock') +
        kpi('Abandonment Rate',    fmtPct(m.abandonRate), deltaArrow(m.abandonRate, prev.abandonRate, false), 'danger', 'fas fa-ban');
}

function renderMetricsCharts(m) {
    // Destroy previous
    Object.values(metricsCharts).forEach(c => { try { c.destroy(); } catch(e){} });
    Object.keys(metricsCharts).forEach(k => delete metricsCharts[k]);

    // Commercial donut
    metricsCharts.comm = new ApexCharts(document.getElementById('chartCommercial'), {
        chart: {type: 'donut', height: 220},
        series: [m.won, m.lost, m.noDec],
        labels: ['Won', 'Lost', 'No Decision'],
        colors: ['#198754', '#dc3545', '#6c757d'],
        legend: {position: 'bottom'},
    });
    metricsCharts.comm.render();

    // Technical donut
    metricsCharts.tech = new ApexCharts(document.getElementById('chartTechnical'), {
        chart: {type: 'donut', height: 220},
        series: [m.techW, m.techP, m.techL],
        labels: ['Technical Win', 'Partial', 'Technical Loss'],
        colors: ['#0d6efd', '#795548', '#6f1d1b'],
        legend: {position: 'bottom'},
    });
    metricsCharts.tech.render();

    // Matrix as heatmap-like table
    const matrixBox = document.getElementById('chartMatrix');
    let mh = '<table class="table table-sm text-center mb-0" style="font-size:.85rem;"><thead><tr><th></th><th>Won</th><th>Lost</th><th>No Dec</th></tr></thead><tbody>';
    for (const tech of ['Technical Win','Partial','Technical Loss']) {
        mh += `<tr><th class="text-start small">${tech}</th>`;
        for (const comm of ['Won','Lost','No Decision']) {
            const v = m.matrix[tech][comm];
            const bg = v === 0 ? '#f8f9fa' :
                (tech === 'Technical Win' && comm === 'Won') ? '#198754' :
                (tech === 'Technical Loss' && comm === 'Lost') ? '#dc3545' :
                (tech === 'Technical Win' && comm === 'Lost') ? '#fd7e14' :
                (tech === 'Technical Loss' && comm === 'Won') ? '#0dcaf0' : '#adb5bd';
            const col = v === 0 ? '#adb5bd' : '#fff';
            mh += `<td style="background:${bg};color:${col};font-weight:700;">${v}</td>`;
        }
        mh += '</tr>';
    }
    mh += '</tbody></table>';
    matrixBox.innerHTML = mh;

    // Won amount by month
    const months = Object.keys(m.byMonth).sort();
    metricsCharts.month = new ApexCharts(document.getElementById('chartByMonth'), {
        chart: {type: 'bar', height: 260, toolbar: {show: false}},
        series: [{name: 'Won $', data: months.map(k => m.byMonth[k])}],
        xaxis: {categories: months},
        yaxis: {labels: {formatter: v => fmtMoney(v)}},
        colors: ['#198754'],
        dataLabels: {enabled: false},
    });
    metricsCharts.month.render();

    // Win rate by product
    const products = Object.keys(m.byProduct).sort();
    const rates = products.map(p => {
        const {won, lost} = m.byProduct[p];
        return won + lost > 0 ? (won / (won + lost) * 100) : 0;
    });
    metricsCharts.product = new ApexCharts(document.getElementById('chartByProduct'), {
        chart: {type: 'bar', height: 260, toolbar: {show: false}},
        plotOptions: {bar: {horizontal: true, distributed: true}},
        series: [{name: 'Win rate', data: rates}],
        xaxis: {categories: products, max: 100, labels: {formatter: v => v.toFixed(0) + '%'}},
        colors: ['#0d6efd', '#20c997', '#fd7e14', '#6f42c1', '#dc3545', '#198754', '#ffc107', '#0dcaf0'],
        legend: {show: false},
        dataLabels: {enabled: true, formatter: v => v.toFixed(0) + '%'},
    });
    metricsCharts.product.render();

    // Loss reasons
    const reasons = Object.keys(m.lossReasons).sort((a,b) => m.lossReasons[b] - m.lossReasons[a]);
    metricsCharts.loss = new ApexCharts(document.getElementById('chartLossReasons'), {
        chart: {type: 'bar', height: 220, toolbar: {show: false}},
        plotOptions: {bar: {horizontal: true, distributed: true}},
        series: [{name: 'Count', data: reasons.map(r => m.lossReasons[r])}],
        xaxis: {categories: reasons},
        colors: ['#dc3545', '#fd7e14', '#ffc107', '#0dcaf0', '#6f42c1', '#20c997', '#0d6efd', '#795548', '#adb5bd'],
        legend: {show: false},
        dataLabels: {enabled: true},
    });
    metricsCharts.loss.render();
}

// ═══ Support Activities (Post Sales + Events) ══════════════════════════════
function trrOverlapsRange(t, from, to) {
    // Consideramos que un TRR "aparece" en el rango si su intervalo start→end lo toca
    const sd = t.startDate ? new Date(t.startDate) : null;
    const ed = trrEndDate(t) ? new Date(trrEndDate(t)) : new Date();
    if (!sd) return false;
    return sd <= to && ed >= from;
}

// Amount efectivo de un TRR (finalAmount si Won + closed, si no oppAmount)
function trrEffectiveAmount(t) {
    const fin = parseFloat((t.finalAmount || '').toString().replace(/[",$\s]/g, '')) || 0;
    if (t.projectStatus === 'Closed' && t.commercialOutcome === 'Won' && fin > 0) return fin;
    return parseFloat((t.oppAmount || '').toString().replace(/[",$\s]/g, '')) || 0;
}

// Engagement mix: counts en rango por tipo (aplica product/owner filters)
function computeEngagementMix(filters) {
    const {from, to} = filters;
    const base = metricsFilterTRRs(filters).filter(t => trrOverlapsRange(t, from, to));
    const bytype = {'Opportunity': 0, 'Post Sales': 0, 'Events': 0};
    base.forEach(t => { const k = t.engagementType || 'Opportunity'; if (bytype[k] !== undefined) bytype[k]++; });
    return {total: base.length, opp: bytype['Opportunity'], post: bytype['Post Sales'], event: bytype['Events']};
}

function renderEngagementMix(mix) {
    const pill = (label, val, color, icon) => `
        <div class="col-md-3 col-6">
            <div class="p-2 px-3 d-flex align-items-center justify-content-between rounded border" style="background:#fff;">
                <div>
                    <div class="text-muted text-uppercase" style="font-size:.6rem;letter-spacing:.5px;font-weight:700;">
                        <i class="${icon} me-1" style="color:${color};"></i>${label}
                    </div>
                    <div style="font-size:1.25rem;font-weight:800;">${val}</div>
                </div>
                <span class="badge" style="background:${color};opacity:.85;">TRRs</span>
            </div>
        </div>`;
    document.getElementById('engagementMix').innerHTML =
        pill('Total in range',       mix.total, '#0d6efd', 'fas fa-layer-group') +
        pill('Opportunities',        mix.opp,   '#0dcaf0', 'fas fa-briefcase') +
        pill('Post Sales',           mix.post,  '#6f42c1', 'fas fa-phone-alt') +
        pill('Events',               mix.event, '#ffc107', 'fas fa-calendar-star');
}
function computeSupport(filters) {
    const {from, to} = filters;
    const base = metricsFilterTRRs(filters);
    const posts  = base.filter(t => (t.engagementType || '') === 'Post Sales' && trrOverlapsRange(t, from, to));
    const events = base.filter(t => (t.engagementType || '') === 'Events'     && trrOverlapsRange(t, from, to));
    // Set de cuentas que tienen al menos una Opp (en cualquier estado)
    const oppAccounts = new Set(
        trrList.filter(t => (t.engagementType || 'Opportunity') === 'Opportunity')
               .map(t => (t.accountName || '').toLowerCase().trim())
               .filter(Boolean)
    );
    const supportOnOpp   = [...posts, ...events].filter(t => oppAccounts.has((t.accountName || '').toLowerCase().trim()));
    const supportStandalone = [...posts, ...events].filter(t => !oppAccounts.has((t.accountName || '').toLowerCase().trim()));

    // Amounts por tipo (Post/Events tienen amount desde SFDC en muchos casos)
    const sumAmt = arr => arr.reduce((s, t) => s + trrEffectiveAmount(t), 0);
    const postsAmount  = sumAmt(posts);
    const eventsAmount = sumAmt(events);
    const supportOnOppAmount     = sumAmt(supportOnOpp);
    const supportStandaloneAmount = sumAmt(supportStandalone);

    // Top 10 cuentas por count (Opp + Post + Event en rango)
    const oppsInRange = base.filter(t => (t.engagementType || 'Opportunity') === 'Opportunity' && trrOverlapsRange(t, from, to));
    const byAccountCount  = {};
    const byAccountAmount = {};
    const bumpCount = (acc, kind) => {
        if (!acc) return;
        if (!byAccountCount[acc])  byAccountCount[acc]  = {opp: 0, post: 0, event: 0};
        byAccountCount[acc][kind]++;
    };
    const bumpAmount = (acc, kind, amt) => {
        if (!acc || !(amt > 0)) return;
        if (!byAccountAmount[acc]) byAccountAmount[acc] = {opp: 0, post: 0, event: 0};
        byAccountAmount[acc][kind] += amt;
    };
    oppsInRange.forEach(t => { bumpCount(t.accountName, 'opp');   bumpAmount(t.accountName, 'opp',   trrEffectiveAmount(t)); });
    posts.forEach(       t => { bumpCount(t.accountName, 'post');  bumpAmount(t.accountName, 'post',  trrEffectiveAmount(t)); });
    events.forEach(      t => { bumpCount(t.accountName, 'event'); bumpAmount(t.accountName, 'event', trrEffectiveAmount(t)); });

    const topAccountsByCount = Object.entries(byAccountCount)
        .map(([acc, c]) => ({acc, ...c, total: c.opp + c.post + c.event}))
        .sort((a, b) => b.total - a.total)
        .slice(0, 10);
    const topAccountsByAmount = Object.entries(byAccountAmount)
        .map(([acc, c]) => ({acc, ...c, total: c.opp + c.post + c.event}))
        .sort((a, b) => b.total - a.total)
        .slice(0, 10);

    return {
        posts, events, supportOnOpp, supportStandalone,
        postsAmount, eventsAmount, supportOnOppAmount, supportStandaloneAmount,
        topAccountsByCount, topAccountsByAmount,
    };
}

function renderSupportKPIs(s) {
    const kpi = (label, val, sub = '', color = 'primary', icon = '') => `
        <div class="col-md-3 col-6">
            <div class="card p-3 h-100" style="border-left:4px solid var(--bs-${color});">
                <div class="small text-muted text-uppercase fw-bold" style="font-size:.7rem;letter-spacing:.5px;">${icon ? '<i class="'+icon+' me-1"></i>' : ''}${label}</div>
                <div class="mt-1" style="font-size:1.5rem;font-weight:800;">${val}</div>
                ${sub ? `<div class="small text-muted">${sub}</div>` : ''}
            </div>
        </div>`;
    const amtSub = a => a > 0 ? `${fmtMoney(a)} associated` : 'no amount data';
    document.getElementById('supportKPIs').innerHTML =
        kpi('Post Sales',      s.posts.length,  amtSub(s.postsAmount),           'info',    'fas fa-phone-alt') +
        kpi('Events',          s.events.length, amtSub(s.eventsAmount),          'warning', 'fas fa-calendar-star') +
        kpi('On Opp accounts', s.supportOnOpp.length,      amtSub(s.supportOnOppAmount),     'success',  'fas fa-bullseye') +
        kpi('Standalone',      s.supportStandalone.length, amtSub(s.supportStandaloneAmount),'secondary','fas fa-seedling');
}

function renderSupportCharts(s) {
    ['accounts','accountsAmt','split'].forEach(k => {
        if (metricsCharts[k]) { try { metricsCharts[k].destroy(); } catch(e){} delete metricsCharts[k]; }
    });

    // Top accounts by COUNT
    const labelsCount = s.topAccountsByCount.map(a => a.acc);
    metricsCharts.accounts = new ApexCharts(document.getElementById('chartAccounts'), {
        chart: {type: 'bar', height: 280, stacked: true, toolbar: {show: false}},
        plotOptions: {bar: {horizontal: true}},
        series: [
            {name: 'Opp',        data: s.topAccountsByCount.map(a => a.opp)},
            {name: 'Post Sales', data: s.topAccountsByCount.map(a => a.post)},
            {name: 'Event',      data: s.topAccountsByCount.map(a => a.event)},
        ],
        xaxis: {categories: labelsCount},
        colors: ['#0dcaf0', '#6f42c1', '#ffc107'],
        legend: {position: 'top'},
        dataLabels: {enabled: false},
    });
    metricsCharts.accounts.render();

    // Top accounts by AMOUNT ($)
    const labelsAmt = s.topAccountsByAmount.map(a => a.acc);
    metricsCharts.accountsAmt = new ApexCharts(document.getElementById('chartAccountsAmount'), {
        chart: {type: 'bar', height: 280, stacked: true, toolbar: {show: false}},
        plotOptions: {bar: {horizontal: true}},
        series: [
            {name: 'Opp $',        data: s.topAccountsByAmount.map(a => a.opp)},
            {name: 'Post Sales $', data: s.topAccountsByAmount.map(a => a.post)},
            {name: 'Event $',      data: s.topAccountsByAmount.map(a => a.event)},
        ],
        xaxis: {categories: labelsAmt, labels: {formatter: v => fmtMoney(v)}},
        colors: ['#0dcaf0', '#6f42c1', '#ffc107'],
        legend: {position: 'top'},
        dataLabels: {enabled: false},
        tooltip: {y: {formatter: v => fmtMoney(v)}},
    });
    metricsCharts.accountsAmt.render();

    metricsCharts.split = new ApexCharts(document.getElementById('chartSupportSplit'), {
        chart: {type: 'donut', height: 280},
        series: [s.supportOnOpp.length, s.supportStandalone.length],
        labels: ['On Opp accounts', 'Standalone'],
        colors: ['#198754', '#6c757d'],
        legend: {position: 'bottom'},
    });
    metricsCharts.split.render();
}

function renderMetrics() {
    populateMetricsFilters();
    const filters = metricsCurrentFilters();
    const m = computeMetrics(filters);
    const prev = computePrevPeriodMetrics(filters);
    const s = computeSupport(filters);
    const mix = computeEngagementMix(filters);
    const fmt = d => d.toISOString().substring(0,10);
    const label = filters.label ? `<strong>${filters.label}</strong> · ` : '';
    document.getElementById('mfSummary').innerHTML =
        `${label}Range: <strong>${fmt(filters.from)}</strong> → <strong>${fmt(filters.to)}</strong> · ` +
        `<strong>${mix.total}</strong> TRRs in range (Opps: ${mix.opp} · Post: ${mix.post} · Events: ${mix.event}) · ` +
        `Opps closed: <strong>${m.closed}</strong> · Won: <strong>${m.won}</strong> · Lost: <strong>${m.lost}</strong>`;
    renderEngagementMix(mix);
    renderMetricsKPIs(m, prev);
    renderMetricsCharts(m);
    renderDistrictCharts();
    renderCompensation();
    renderSupportKPIs(s);
    renderSupportCharts(s);
}

// ═══ District Breakdown widgets (stacked) ═════════════════════════════════
function renderDistrictCharts() {
    const filters = metricsCurrentFilters();

    // Base: aplica filtros globales (owner/product) — sólo Opps (Win/Loss no aplica a otros tipos)
    const base = metricsFilterTRRs(filters).filter(t => (t.engagementType || 'Opportunity') === 'Opportunity');
    const parseAmt = v => parseFloat((v || '').toString().replace(/[",$\s]/g, '')) || 0;

    // Bucket por distrito con 4 sub-categorías (count + amount).
    // TRRs sin distrito se acumulan aparte y se muestran como footer note (no en el gráfico).
    const buckets = {};
    const noDistrict = {active: 0, won: 0, lost: 0, noDec: 0};
    const emptyBucket = () => ({
        active: {c: 0, a: 0}, won: {c: 0, a: 0}, lost: {c: 0, a: 0}, noDec: {c: 0, a: 0}
    });
    const classifyKey = (t) => {
        if (t.projectStatus !== 'Closed') return 'active';
        if (!inRange(trrEndDate(t), filters.from, filters.to)) return null;
        if (t.commercialOutcome === 'Won')  return 'won';
        if (t.commercialOutcome === 'Lost') return 'lost';
        if (t.commercialOutcome === 'No Decision') return 'noDec';
        return null;
    };

    base.forEach(t => {
        const key = classifyKey(t);
        if (!key) return;
        const d = (t.district || '').trim();
        if (!d) { noDistrict[key]++; return; }
        if (!buckets[d]) buckets[d] = emptyBucket();
        const amt = key === 'won' ? parseAmt(t.finalAmount) : parseAmt(t.oppAmount);
        buckets[d][key].c++;
        buckets[d][key].a += amt;
    });

    // Ordena distritos por total count desc
    const entries = Object.entries(buckets).sort((a, b) => {
        const ta = a[1].active.c + a[1].won.c + a[1].lost.c + a[1].noDec.c;
        const tb = b[1].active.c + b[1].won.c + b[1].lost.c + b[1].noDec.c;
        return tb - ta;
    });

    const footer = document.getElementById('districtFooterHint');
    const noDistTotal = noDistrict.active + noDistrict.won + noDistrict.lost + noDistrict.noDec;

    ['distCount', 'distAmount'].forEach(k => {
        if (metricsCharts[k]) { try { metricsCharts[k].destroy(); } catch(e){} delete metricsCharts[k]; }
    });

    // Footer note sobre TRRs sin distrito (siempre visible si hay alguno)
    if (footer) {
        if (noDistTotal > 0) {
            const parts = [];
            if (noDistrict.active) parts.push(`${noDistrict.active} Active`);
            if (noDistrict.won)    parts.push(`${noDistrict.won} Won`);
            if (noDistrict.lost)   parts.push(`${noDistrict.lost} Lost`);
            if (noDistrict.noDec)  parts.push(`${noDistrict.noDec} No Decision`);
            footer.innerHTML = `<i class="fas fa-info-circle text-warning me-1"></i>${noDistTotal} TRR${noDistTotal===1?'':'s'} without an assigned district (not shown in the chart): ${parts.join(' · ')}. Edit each TRR to assign the District.`;
        } else {
            footer.innerHTML = '';
        }
    }

    if (entries.length === 0) {
        document.getElementById('chartDistrictCount').innerHTML  = '<div class="text-muted small p-3 text-center">No TRRs with a district in the selected range/filters.</div>';
        document.getElementById('chartDistrictAmount').innerHTML = '';
        return;
    }

    const catMeta = {
        active: {label: 'Active',      color: '#0d6efd'},
        won:    {label: 'Won',         color: '#198754'},
        lost:   {label: 'Lost',        color: '#dc3545'},
        noDec:  {label: 'No Decision', color: '#6c757d'},
    };
    const cats = ['active', 'won', 'lost', 'noDec'];
    const labels = entries.map(([d]) => d);
    const seriesCount  = cats.map(k => ({name: catMeta[k].label, data: entries.map(([_, b]) => b[k].c)}));
    const seriesAmount = cats.map(k => ({name: catMeta[k].label, data: entries.map(([_, b]) => b[k].a)}));
    const colors = cats.map(k => catMeta[k].color);

    const baseOpts = {
        chart: {type: 'bar', height: 260, stacked: true, toolbar: {show: false}},
        plotOptions: {bar: {horizontal: true, borderRadius: 3}},
        colors: colors,
        legend: {position: 'top', horizontalAlign: 'right', fontSize: '11px'},
        dataLabels: {enabled: false},
    };

    metricsCharts.distCount = new ApexCharts(document.getElementById('chartDistrictCount'), {
        ...baseOpts,
        series: seriesCount,
        xaxis: {categories: labels, labels: {formatter: v => Math.round(v).toString()}},
        tooltip: {y: {formatter: v => `${v} TRR${v === 1 ? '' : 's'}`}},
    });
    metricsCharts.distCount.render();

    metricsCharts.distAmount = new ApexCharts(document.getElementById('chartDistrictAmount'), {
        ...baseOpts,
        series: seriesAmount,
        xaxis: {categories: labels, labels: {formatter: v => fmtMoney(v)}},
        tooltip: {y: {formatter: v => fmtMoney(v)}},
    });
    metricsCharts.distAmount.render();
}

// ═══ Compensation widgets ═════════════════════════════════════════════════
function parseAmtSafe(v) {
    return parseFloat((v || '').toString().replace(/[",$\s]/g, '')) || 0;
}

function computeCompensation(filters) {
    // Considera SÓLO Opps Won cerradas dentro del rango, aplicando filtros globales de producto/owner
    const base = metricsFilterTRRs(filters).filter(t =>
        (t.engagementType || 'Opportunity') === 'Opportunity' &&
        t.projectStatus === 'Closed' &&
        t.commercialOutcome === 'Won' &&
        inRange(trrEndDate(t), filters.from, filters.to)
    );

    const plan = getCompPlan();
    const target = individualTarget();

    let newAmount = 0, renewAmount = 0;
    const byMonth = {}; // key YYYY-MM → {new, renew, credit}
    const dailyCredit = []; // [{date, credit}] para curva acumulada

    base.forEach(t => {
        const total = parseAmtSafe(t.finalAmount);
        const renew = Math.max(0, Math.min(parseAmtSafe(t.renewAmount), total));
        const newB  = total - renew;
        newAmount   += newB;
        renewAmount += renew;

        const d = new Date(trrEndDate(t));
        if (isNaN(d)) return;
        const key = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`;
        if (!byMonth[key]) byMonth[key] = {new: 0, renew: 0, credit: 0};
        byMonth[key].new   += newB;
        byMonth[key].renew += renew;
        byMonth[key].credit += newB * plan.bcrNew + renew * plan.bcrRenew;

        dailyCredit.push({date: d, credit: newB * plan.bcrNew + renew * plan.bcrRenew});
    });

    const totalBookings   = newAmount + renewAmount;
    const quotaRetirement = newAmount * plan.bcrNew;
    const variableCredit  = newAmount * plan.bcrNew + renewAmount * plan.bcrRenew;
    const individualAttPct = target > 0 ? (quotaRetirement / target) * 100 : 0;
    const variableAttPct   = target > 0 ? (variableCredit  / target) * 100 : 0;
    const groupSharePct    = plan.quotaGroup > 0 ? (quotaRetirement / plan.quotaGroup) * 100 : 0;
    const groupShareTarget = 100 / Math.max(1, plan.groupSize);
    // OTI% is a share of total OTE (BASE = 100−OTI% of OTE, OTI = OTI% of OTE)
    // → variable_target = BASE × OTI/(100−OTI). With OTI=20% ⇒ BASE/4.
    const otiPct = Math.max(0, Math.min(plan.otiPct, 99.9));
    const variableTargetEur = plan.base * otiPct / (100 - otiPct);
    const variablePayoutEur = variableTargetEur * variableAttPct / 100;

    return {
        plan, target, newAmount, renewAmount, totalBookings,
        quotaRetirement, variableCredit,
        individualAttPct, variableAttPct, groupSharePct, groupShareTarget,
        variableTargetEur, variablePayoutEur,
        oteEur: plan.base + variablePayoutEur,
        byMonth, dailyCredit, wonCount: base.length,
    };
}

function renderCompensation() {
    const filters = metricsCurrentFilters();
    const c = computeCompensation(filters);
    const plan = c.plan;
    const box = document.getElementById('compKPIs');
    const emptyHint = document.getElementById('compEmptyHint');

    // Mostrar hint si falta configurar lo básico (BASE o quota)
    const configured = plan.base > 0 || plan.quotaGroup > 0;
    if (emptyHint) emptyHint.style.display = configured ? 'none' : '';

    const kpi = (label, val, sub = '', color = 'primary', icon = '') => `
        <div class="col-md-4 col-sm-6">
            <div class="card p-3 h-100" style="border-left:4px solid var(--bs-${color});">
                <div class="small text-muted text-uppercase fw-bold" style="font-size:.7rem;letter-spacing:.5px;">${icon ? '<i class="'+icon+' me-1"></i>' : ''}${label}</div>
                <div class="mt-1" style="font-size:1.5rem;font-weight:800;">${val}</div>
                ${sub ? `<div class="small text-muted">${sub}</div>` : ''}
            </div>
        </div>`;

    box.innerHTML =
        // Row 1 — bookings breakdown
        kpi('Total Bookings',    fmtMoneyFull(c.totalBookings),    `${c.wonCount} Won deals`,                          'success', 'fas fa-hand-holding-usd') +
        kpi('Upsell + Net New',  fmtMoneyFull(c.newAmount),        `× BCR ${plan.bcrNew.toFixed(2)}`,                  'primary', 'fas fa-plus-circle') +
        kpi('Renewal',           fmtMoneyFull(c.renewAmount),      `× BCR ${plan.bcrRenew.toFixed(2)} (no quota)`,     'info',    'fas fa-sync-alt') +
        // Row 2 — quota & payout
        kpi('Quota Retirement',  fmtMoneyFull(c.quotaRetirement),  `Individual target: ${fmtMoneyFull(c.target)}`,     'success', 'fas fa-crosshairs') +
        kpi('Attainment',        c.individualAttPct.toFixed(1) + '%', `Variable-adj: ${c.variableAttPct.toFixed(1)}%`, 'warning', 'fas fa-percent') +
        kpi('Variable Payout',   fmtMoneyFull(c.variablePayoutEur),  '', 'primary', 'fas fa-wallet');

    // Barras de progreso
    const bar = (host, pctRaw, colorGood, colorOver, sub, extraTargetPct) => {
        const el = document.getElementById(host);
        if (!el) return;
        const pct = Math.max(0, pctRaw);
        const clipped = Math.min(pct, 100);
        const over = pct > 100;
        const overWidth = over ? Math.min(pct - 100, 100) : 0;
        const markerHtml = (typeof extraTargetPct === 'number' && extraTargetPct > 0 && extraTargetPct < 100)
            ? `<div style="position:absolute; left:${extraTargetPct}%; top:-4px; bottom:-4px; width:2px; background:#000; opacity:.45;" title="Target ${extraTargetPct.toFixed(1)}%"></div>` : '';
        el.innerHTML = `
            <div style="position:relative; height:22px; background:#e9ecef; border-radius:11px; overflow:hidden;">
                <div style="height:100%; width:${clipped}%; background:${colorGood}; transition:width .4s;"></div>
                ${over ? `<div style="position:absolute; top:0; left:100%; height:100%; width:${overWidth}%; background:${colorOver};"></div>` : ''}
                ${markerHtml}
            </div>
            <div class="d-flex justify-content-between small mt-1">
                <span class="fw-bold" style="color:${over ? colorOver : colorGood};">${pct.toFixed(1)}%</span>
                <span class="text-muted">${sub}</span>
            </div>`;
    };
    bar('compAttainmentBar', c.individualAttPct, '#198754', '#0d6efd',
        `${fmtMoneyFull(c.quotaRetirement)} of ${fmtMoneyFull(c.target)}`);
    document.getElementById('compAttainmentSub').innerHTML =
        `Variable-adjusted (includes renew × ${plan.bcrRenew}): <strong>${c.variableAttPct.toFixed(1)}%</strong> → payout <strong>${fmtMoneyFull(c.variablePayoutEur)}</strong>`;

    bar('compGroupShareBar', c.groupSharePct, '#0dcaf0', '#6f42c1',
        `${fmtMoneyFull(c.quotaRetirement)} of ${fmtMoneyFull(plan.quotaGroup)}`,
        c.groupShareTarget);
    document.getElementById('compGroupShareSub').innerHTML =
        `Individual contribution target: <strong>${c.groupShareTarget.toFixed(1)}%</strong> (100 / ${plan.groupSize} presales). Black marker on the bar.`;

    // Chart mensual (Upsell+NetNew vs Renew stacked)
    ['compMonth','compCumul'].forEach(k => {
        if (metricsCharts[k]) { try { metricsCharts[k].destroy(); } catch(e){} delete metricsCharts[k]; }
    });
    const months = Object.keys(c.byMonth).sort();
    metricsCharts.compMonth = new ApexCharts(document.getElementById('compChartMonth'), {
        chart: {type: 'bar', height: 280, stacked: true, toolbar: {show: false}},
        series: [
            {name: 'Upsell+NetNew', data: months.map(k => c.byMonth[k].new)},
            {name: 'Renew',         data: months.map(k => c.byMonth[k].renew)},
        ],
        xaxis: {categories: months},
        yaxis: {labels: {formatter: v => fmtMoney(v)}},
        colors: ['#0d6efd', '#0dcaf0'],
        dataLabels: {enabled: false},
        legend: {position: 'top'},
        tooltip: {y: {formatter: v => fmtMoneyFull(v)}},
    });
    metricsCharts.compMonth.render();

    // Curva de attainment acumulado a lo largo del FY / rango
    const sorted = c.dailyCredit.slice().sort((a,b) => a.date - b.date);
    let acc = 0;
    const points = sorted.map(p => {
        acc += p.credit;
        return {x: p.date.getTime(), y: c.target > 0 ? (acc / c.target * 100) : 0};
    });
    const fmt = d => d.toISOString().substring(0,10);
    const linearTargetSerie = [
        {x: filters.from.getTime(), y: 0},
        {x: filters.to.getTime(),   y: 100},
    ];
    metricsCharts.compCumul = new ApexCharts(document.getElementById('compChartCumulative'), {
        chart: {type: 'line', height: 280, toolbar: {show: false}, animations: {enabled: false}},
        series: [
            {name: 'Attainment %', data: points.length ? points : [{x: filters.from.getTime(), y: 0}, {x: filters.to.getTime(), y: 0}]},
            {name: 'Linear target', data: linearTargetSerie},
        ],
        stroke: {curve: 'stepline', width: [3, 2], dashArray: [0, 5]},
        colors: ['#198754', '#adb5bd'],
        xaxis: {type: 'datetime'},
        yaxis: {labels: {formatter: v => v.toFixed(0) + '%'}, min: 0},
        annotations: {yaxis: [{y: 100, borderColor: '#dc3545', label: {text: '100%', style: {color: '#fff', background: '#dc3545'}}}]},
        markers: {size: [4, 0]},
        legend: {position: 'top'},
    });
    metricsCharts.compCumul.render();
}

function exportMetricsCSV() {
    const filters = metricsCurrentFilters();
    const base = metricsFilterTRRs(filters);
    const rows = [
        ['id','trrName','account','owner','type','products','status','startDate','closedDate','oppAmount','finalAmount','renewAmount','newBusinessAmount','commercialOutcome','technicalOutcome','lossReason']
    ];
    base.forEach(t => {
        const fa = parseFloat((t.finalAmount || '').toString().replace(/[",$\s]/g,'')) || 0;
        const ra = Math.max(0, Math.min(parseFloat((t.renewAmount || '').toString().replace(/[",$\s]/g,'')) || 0, fa));
        const nb = t.commercialOutcome === 'Won' ? Math.max(0, fa - ra) : 0;
        rows.push([
            t.id, t.trrName, t.accountName, t.ownerName, t.engagementType || 'Opportunity',
            t.cortexProduct || '', t.projectStatus, t.startDate || '', trrEndDate(t),
            t.oppAmount || '', t.finalAmount || '', t.renewAmount || '0', nb || '',
            t.commercialOutcome || '', t.technicalOutcome || '', t.lossReason || '',
        ]);
    });
    const csv = rows.map(r => r.map(v => {
        const s = String(v ?? '').replace(/"/g,'""');
        return /[",\n]/.test(s) ? `"${s}"` : s;
    }).join(',')).join('\n');
    const blob = new Blob([csv], {type: 'text/csv;charset=utf-8'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `povradar_metrics_${filters.from.toISOString().slice(0,10)}_${filters.to.toISOString().slice(0,10)}.csv`;
    document.body.appendChild(a); a.click(); a.remove();
}

</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>