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
    </div>

    <a href="../index.php" class="btn btn-back">⬅️ Back to PANTools</a>
</div>

<div class="main-wrapper">
    
    <div class="top-navbar">
        <ul class="nav nav-pills" id="viewTabs">
            <li class="nav-item">
                <a class="nav-link active" href="#" onclick="showDashboard()" id="tabList"><i class="fas fa-list me-1"></i> List View</a>
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
        
        <div id="dashboardView" class="view-section active">
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
                            <select class="form-select" id="projectStatus">
                                <option value="On Track">🟢 On Track</option>
                                <option value="At Risk">🟡 At Risk</option>
                                <option value="Planned">🔵 Planned</option>
                                <option value="Not Started">⚪ Not Started</option>
                                <option value="Parked">🟤 Parked</option>
                                <option value="Closed">⚫ Closed</option>
                            </select>
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

<script>
    let trrList = [];
    let chartInstance = null;
    let defaultOwner = '';
    let currentSort = 'date'; 

    document.addEventListener('DOMContentLoaded', () => {
        loadFromStorage();
        checkDefaultOwner(); 
        showDashboard(); 
        const dateInput = document.getElementById('creationDate');
        if(dateInput) dateInput.valueAsDate = new Date();
    });

    function loadFromStorage() {
        const data = localStorage.getItem('pov_radar_data');
        if (data) trrList = JSON.parse(data);
        const storedOwner = localStorage.getItem('pov_radar_default_owner');
        if(storedOwner) defaultOwner = storedOwner;
        
        renderSidebarStats();
        renderUserBadge();
    }

    function checkDefaultOwner() {
        if (!defaultOwner) {
            setTimeout(() => {
                const name = prompt("Welcome to PoV Radar!\nPlease enter your name (Presales/Owner):");
                if (name && name.trim() !== "") {
                    defaultOwner = name.trim();
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
            location.reload();
        }
    }

    function deleteAllData() {
        if(confirm("⚠ WARNING: DELETE ALL DATA?")) {
            trrList = [];
            localStorage.setItem('pov_radar_data', JSON.stringify(trrList));
            refreshActiveView();
            renderSidebarStats();
        }
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

    if (reset) {
        document.getElementById('trrForm').reset();
        document.getElementById('trrId').value = ''; 
        document.getElementById('formTitle').innerText = 'New Report';
        document.getElementById('creationDate').valueAsDate = new Date();
        document.getElementById('complexity').value = 'Medium';
        document.getElementById('workload').value = 'Normal';
        document.getElementById('engagementType').value = 'Opportunity'; 
        document.getElementById('oppAmount').value = '';

        const select = document.getElementById('cortexProduct');
        Array.from(select.options).forEach(opt => opt.selected = false);

        if(defaultOwner) document.getElementById('ownerName').value = defaultOwner;
    }

    document.getElementById('formView').classList.add('active');
    }



    function applyQuickFilter(type) {
        document.getElementById('globalSearch').value = '';
        document.querySelectorAll('.status-filter').forEach(c => c.checked = true);
        document.querySelectorAll('.eng-filter').forEach(c => c.checked = true);
        document.getElementById('btnClosed').checked = false;
        
        document.querySelectorAll('.nav-link-sidebar').forEach(l => l.classList.remove('active'));
        currentSort = 'date';

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

        refreshActiveView();
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
        if (val >= 1000000) return '$' + (val / 1000000).toFixed(1) + 'M';
        if (val >= 1000) return '$' + (val / 1000).toFixed(0) + 'k';
        return '$' + val.toFixed(0);
    }

function renderSidebarStats() {
  let totalAmount = 0;
  let activeCount = 0;

  trrList.forEach(item => {
    const status = (item.projectStatus || '').trim();
    const type = (item.engagementType || 'Opportunity').trim(); // default por compatibilidad

    // Active items = todo menos Closed (si quieres)
    if (status !== 'Closed') activeCount++;

    // Total Value = SOLO Closed + Opportunity
    if (status != 'Closed') {
      const cleanAmt = parseFloat((item.oppAmount || '0').toString().replace(/[",$\s]/g, ''));
      if (!isNaN(cleanAmt)) totalAmount += cleanAmt;
    }
  });

  document.getElementById('kpiTotalAmount').innerText = formatCurrency(totalAmount);
  document.getElementById('kpiTotalCount').innerText = activeCount;
}


    function exportData(e) {
        if(e) e.preventDefault();
        if (!trrList || trrList.length === 0) { alert("No data to export."); return; }
        const dataStr = JSON.stringify(trrList, null, 2);
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
                    const importedData = JSON.parse(e.target.result);
                    if (Array.isArray(importedData)) {
                        importedData.forEach(importedItem => {
                            const existingIndex = trrList.findIndex(t => t.id === importedItem.id);
                            if (existingIndex >= 0) trrList[existingIndex] = importedItem;
                            else trrList.push(importedItem);
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

    function renderForecast() {
        const tbody = document.getElementById('forecastBody');
        const headerRow = document.getElementById('forecastHeader');
        if(!tbody || !headerRow) return;
        const weeks = getNext4Weeks();
        headerRow.innerHTML = '<th class="text-start ps-3" style="width: 20%;">Owner</th>';
        const weekLabels = ["This Week", "Next Week", "Week +2", "Week +3"];
        weeks.forEach((w, idx) => {
            const dateStr = `${w.start.getDate()}/${w.start.getMonth()+1}`;
            headerRow.innerHTML += `<th>${weekLabels[idx]} <br><small class="fw-normal text-muted">${dateStr}</small></th>`;
        });
        const owners = [...new Set(trrList.map(i => i.ownerName || 'Unassigned'))].sort();
        if(owners.length === 0) { tbody.innerHTML = '<tr><td colspan="5" class="text-center py-3">No active data</td></tr>'; return; }
        tbody.innerHTML = '';
        owners.forEach(owner => {
            let rowHtml = `<tr><td class="fw-bold ps-3">${owner}</td>`;
            weeks.forEach(week => {
                let points = 0; let count = 0;
                trrList.forEach(item => {
                    if ((item.ownerName || 'Unassigned') !== owner) return;
                    if (['Parked', 'Not Started', 'Closed'].includes(item.projectStatus)) return;
                    const pStart = new Date(item.startDate);
                    const pEnd = new Date(item.endDate);
                    if (pStart <= week.end && pEnd >= week.start) {
                        const compScore = { 'Low': 1, 'Medium': 2, 'High': 4 };
                        const workScore = { 'Light': 0, 'Normal': 1, 'Heavy': 3 };
                        const cVal = compScore[item.complexity] || 2;
                        const wVal = (workScore[item.workload] !== undefined) ? workScore[item.workload] : 1; 
                        points += (cVal + wVal);
                        count++;
                    }
                });
                let icon = '💤'; let bgStyle = 'background-color: #f8f9fa; color: #6c757d; border: 1px solid #dee2e6;';
                if (points >= 39) { icon = '🔥'; bgStyle = 'background-color: #dc3545; color: white;'; } 
                else if (points >= 26) { icon = '🥵'; bgStyle = 'background-color: #fd7e14; color: white;'; } 
                else if (points >= 16) { icon = '🟡'; bgStyle = 'background-color: #ffc107; color: #212529;'; } 
                else if (points >= 6) { icon = '🟢'; bgStyle = 'background-color: #d1e7dd; color: #0f5132; border: 1px solid #badbcc;'; }
                if (points === 0) rowHtml += `<td class="forecast-cell"><div class="forecast-badge" style="${bgStyle}">${icon} Free</div></td>`;
                else rowHtml += `<td class="forecast-cell"><div class="forecast-badge" style="${bgStyle}"><span class="me-2 fs-6">${icon}</span><span><strong>${points} pts</strong> <small>(${count})</small></span></div></td>`;
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
        const trrData = {
            id: document.getElementById('trrId').value || Date.now().toString(),
            trrName: document.getElementById('trrName').value,
            creationDate: document.getElementById('creationDate').value,
            accountName: document.getElementById('accountName').value,
            ownerName: currentOwnerInput || 'Unassigned',
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
            comments: document.getElementById('comments').value
        };
        // Si el usuario marca el TRR como Closed, poner Est. End Date = hoy
        if (trrData.projectStatus === 'Closed') {
        trrData.endDate = getTodayLocalISO();
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
        const amountDisplay = cleanAmt > 0 ? `<h3 class="text-success fw-bold">${formatCurrency(cleanAmt)}</h3>` : '';

        modalBody.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
                <h4>${item.trrName} ${engBadge} <small class="text-muted">| ${item.accountName}</small></h4>
            </div>
            <div class="mb-3">
                ${productsHtml}
                <span class="badge bg-light text-dark border ms-1">Owner: ${item.ownerName || 'N/A'}</span>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>${amountDisplay}</div>
                <div class="d-flex gap-2">
                    <span class="badge ${getStatusClass(item.projectStatus)} align-self-center" style="font-size:1rem">${item.projectStatus}</span>
                    ${linksHtml}
                </div>
            </div>
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
                <td><span class="status-badge ${statusClass}">${item.projectStatus}</span></td>
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
            
            seriesData.push({
                x: uniqueLabel,
                y: [new Date(item.startDate).getTime(), new Date(item.endDate).getTime()],
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
    const dataRows = rows.slice(1);

    const sfdcMap = new Map();
    const linkBase = 'https://paloaltonetworks.lightning.force.com/lightning/r';

    dataRows.forEach((rowString) => {
      if (!rowString || rowString.trim() === '') return;

      const row = parseCSVLine(rowString);
      if (!row || row.length < 11) return; // ideal: 12, pero 11 cubre amount/owner/status

      let trrId = (row[0] || '').replace(/"/g, '').trim();
      if (!trrId.toUpperCase().startsWith('TRR')) return;

      // CSV indices:
      // 7 Engagement Type, 8 Net Opportunity Amount, 9 Assigned Resource, 10 Engagement Status
      const engType = (row[7] || '').replace(/"/g, '').trim();

      let rawAmount = (row[8] || '').replace(/"/g, '').trim();
      let cleanAmount = rawAmount.replace(/[^0-9.-]+/g, '');
      if (cleanAmount === '' || isNaN(parseFloat(cleanAmount))) cleanAmount = '0';

      // ✅ REGLA: si es Post Sales, fuerza amount = 0
      if (engType === 'Post Sales') cleanAmount = '0';

      sfdcMap.set(trrId, {
        createdDate: (row[1] || '').replace(/"/g, '').trim(),
        account: (row[2] || '').replace(/"/g, '').trim(),
        rawTech: (row[3] || '').replace(/"/g, '').trim(),
        sfdcTrrId: (row[4] || '').replace(/"/g, '').trim(),
        sfdcOppId: (row[5] || '').replace(/"/g, '').trim(),
        sfdcExtId: (row[6] || '').replace(/"/g, '').trim(),

        engType: engType,
        oppAmount: cleanAmount,
        owner: (row[9] || '').replace(/"/g, '').trim(),
        statusRaw: (row[10] || '').replace(/"/g, '').trim()
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
        existing.cortexProduct = (data.rawTech || '')
          .split(';')
          .map((t) => t.trim())
          .filter(Boolean)
          .join(', ');

        if (data.engType) existing.engagementType = data.engType;

        // ✅ Siempre actualiza amount (ya viene 0 si Post Sales)
        existing.oppAmount = data.oppAmount;

        updatedCount++;
      }
    });

    // ---- AUTO-CLOSE MISSING TRRs ----
    trrList.forEach((item) => {
      if (!item.id || !item.id.toUpperCase().startsWith('TRR')) {
        manualSkippedCount++;
        return;
      }
      if (item.projectStatus !== 'Closed' && !sfdcMap.has(item.id)) {
        item.projectStatus = 'Closed';
        item.endDate = getTodayLocalISO();

        const dateStr = new Date().toLocaleDateString();
        item.progress = (item.progress || '') + `\n[${dateStr}] Auto-closed: Missing in SFDC export.`;
        closedCount++;
      }
    });

    localStorage.setItem('pov_radar_data', JSON.stringify(trrList));
    alert(
      `Sync Complete:\n\n➕ Created: ${createdCount}\n✏️ Updated: ${updatedCount}\n🔒 Auto-Closed: ${closedCount}\n🛡️ Manual Kept: ${manualSkippedCount}`
    );

    input.value = '';
    showDashboard();
  };

  reader.readAsText(file);
}
function getTodayLocalISO() {
  const d = new Date();
  const tz = d.getTimezoneOffset() * 60000;
  return new Date(d.getTime() - tz).toISOString().split('T')[0]; // YYYY-MM-DD
}

</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>