package main

import (
	"crypto/md5"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"log"
	"math"
	"net"
	"net/http"
	"os"
	"sort"
	"strings"
	"sync"
	"time"

	_ "modernc.org/sqlite"
)

type NetworkNode struct {
	ID                 int64    `json:"id"`
	Identifier         string   `json:"identifier"`
	Name               string   `json:"name"`
	Type               string   `json:"type"`
	NodeSubtype        *string  `json:"node_subtype"`
	ParentIdentifier   *string  `json:"parent_identifier"`
	Lat                float64  `json:"lat"`
	Lng                float64  `json:"lng"`
	ParentLat          *float64 `json:"parent_lat"`
	ParentLng          *float64 `json:"parent_lng"`
	DistanceMeters     float64  `json:"distance_meters"`
	CapacityPorts      int      `json:"capacity_ports"`
	UsedPorts          int      `json:"used_ports"`
	FiberCoreColor     *string  `json:"fiber_core_color"`
	OpticalPowerRx     *float64 `json:"optical_power_rx"`
	OpticalPowerStatus string   `json:"optical_power_status"`
	AttenuationDB      *float64 `json:"attenuation_db"`
	Status             string   `json:"status"`
	HasProblem         bool     `json:"has_problem"`
	IsFlowing          bool     `json:"is_flowing"`
	Detail             string   `json:"detail"`
	Notes              *string  `json:"notes"`
}

type RTOAlert struct {
	IP   string `json:"ip"`
	Name string `json:"name"`
	OLT  string `json:"olt"`
}

type HistorySnapshotItem struct {
	ID        int64   `json:"id"`
	Time      string  `json:"time"`
	CPU       int     `json:"cpu"`
	RAM       int     `json:"ram"`
	Active    int     `json:"active"`
	RTO       int     `json:"rto"`
	RxMbps    float64 `json:"rx_mbps"`
	TxMbps    float64 `json:"tx_mbps"`
}

type TopologyPayload struct {
	MikrotikError   *string               `json:"mikrotikError"`
	Resource        RouterResource        `json:"resource"`
	Clients         []ClientItem          `json:"clients"`
	Interfaces      []string              `json:"interfaces"`
	TopologyData    []NetworkNode         `json:"topologyData"`
	RTOList         []RTOAlert            `json:"rtoList"`
	HistorySnapshots []HistorySnapshotItem `json:"historySnapshots,omitempty"`
	Summary         map[string]any        `json:"summary"`
}

var (
	db               *sql.DB
	cacheMu          sync.RWMutex
	latestPayload    TopologyPayload
	strictModeFlag   bool
	lastTopologyHash string

	sseMu      sync.Mutex
	sseClients = make(map[chan []byte]bool)

	lastSnapshotTime time.Time
)

var mtConfig = RouterConfig{
	Host: getEnv("MIKROTIK_HOST", "103.142.194.75"),
	User: getEnv("MIKROTIK_USER", "magang"),
	Pass: getEnv("MIKROTIK_PASS", "2026##"),
	Port: getEnv("MIKROTIK_PORT", "8777"),
}

func getEnv(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func enableCORS(w http.ResponseWriter) {
	w.Header().Set("Access-Control-Allow-Origin", "*")
	w.Header().Set("Access-Control-Allow-Methods", "GET, POST, PATCH, DELETE, OPTIONS")
	w.Header().Set("Access-Control-Allow-Headers", "Content-Type, Authorization, X-Requested-With")
}

func initDB() {
	dbPath := "./topology.db"
	if envPath := os.Getenv("DB_DATABASE"); envPath != "" {
		dbPath = envPath
	} else if _, err := os.Stat("./database/database.sqlite"); err == nil {
		dbPath = "./database/database.sqlite"
	} else if _, err := os.Stat("../database/database.sqlite"); err == nil {
		dbPath = "../database/database.sqlite"
	}

	var err error
	db, err = sql.Open("sqlite", dbPath)
	if err != nil {
		log.Fatalf("Gagal membuka SQLite (%s): %v", dbPath, err)
	}

	// Schema migrasi ODN & Telemetry
	_, _ = db.Exec(`
		CREATE TABLE IF NOT EXISTS network_nodes (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			identifier TEXT UNIQUE NOT NULL,
			name TEXT NOT NULL,
			type TEXT NOT NULL,
			node_subtype TEXT,
			parent_identifier TEXT,
			capacity_ports INTEGER DEFAULT 8,
			used_ports INTEGER DEFAULT 0,
			fiber_core_color TEXT,
			optical_power_rx REAL,
			attenuation_db REAL,
			notes TEXT,
			latitude REAL NOT NULL,
			longitude REAL NOT NULL
		);
		CREATE TABLE IF NOT EXISTS telemetry_snapshots (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			cpu_load INTEGER DEFAULT 0,
			memory_usage INTEGER DEFAULT 0,
			active_clients INTEGER DEFAULT 0,
			rto_clients INTEGER DEFAULT 0,
			traffic_rx_mbps REAL DEFAULT 0.0,
			traffic_tx_mbps REAL DEFAULT 0.0,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP
		);
	`)

	log.Println("⚡ Go Daemon terhubung ke SQLite:", dbPath)
}

func haversineMeters(lat1, lon1, lat2, lon2 float64) float64 {
	const R = 6371000.0
	dLat := (lat2 - lat1) * math.Pi / 180.0
	dLon := (lon2 - lon1) * math.Pi / 180.0
	a := math.Sin(dLat/2)*math.Sin(dLat/2) +
		math.Cos(lat1*math.Pi/180.0)*math.Cos(lat2*math.Pi/180.0)*
			math.Sin(dLon/2)*math.Sin(dLon/2)
	return R * (2 * math.Atan2(math.Sqrt(a), math.Sqrt(1-a)))
}

func syncAndBuildPayload(strict bool) TopologyPayload {
	start := time.Now()
	resource, ifaceMap, clients, neighbors, err := fetchRouterSnapshot(mtConfig, strict)

	sort.Slice(clients, func(i, j int) bool {
		return clients[i].IPAddress < clients[j].IPAddress
	})

	var mtErrStr *string
	if err != nil {
		s := err.Error()
		mtErrStr = &s
	} else {
		autoDiscoverSQLite(resource, ifaceMap, clients, neighbors)
	}

	// Query network_nodes dengan field lengkap ODN/FTTH
	rows, qErr := db.Query(`
		SELECT id, identifier, name, type, node_subtype, parent_identifier, latitude, longitude,
		       capacity_ports, used_ports, fiber_core_color, optical_power_rx, attenuation_db, notes
		FROM network_nodes
	`)

	nodeMap := make(map[string]NetworkNode)
	childCounts := make(map[string]int)

	if qErr == nil && rows != nil {
		defer rows.Close()
		for rows.Next() {
			var n NetworkNode
			var subtype, parent, color, notes sql.NullString
			var capPorts, usedPorts sql.NullInt64
			var optRx, attDb sql.NullFloat64

			if err := rows.Scan(
				&n.ID, &n.Identifier, &n.Name, &n.Type, &subtype, &parent, &n.Lat, &n.Lng,
				&capPorts, &usedPorts, &color, &optRx, &attDb, &notes,
			); err == nil {
				if subtype.Valid {
					s := subtype.String
					n.NodeSubtype = &s
				}
				if parent.Valid {
					p := parent.String
					n.ParentIdentifier = &p
					childCounts[p]++
				}
				if capPorts.Valid {
					n.CapacityPorts = int(capPorts.Int64)
				} else {
					n.CapacityPorts = 8
				}
				if usedPorts.Valid {
					n.UsedPorts = int(usedPorts.Int64)
				}
				if color.Valid {
					c := color.String
					n.FiberCoreColor = &c
				}
				if optRx.Valid {
					rx := optRx.Float64
					n.OpticalPowerRx = &rx
					if rx >= -23.0 {
						n.OpticalPowerStatus = "optimal"
					} else if rx >= -27.0 {
						n.OpticalPowerStatus = "warning"
					} else {
						n.OpticalPowerStatus = "critical"
					}
				} else {
					n.OpticalPowerStatus = "optimal"
				}
				if attDb.Valid {
					att := attDb.Float64
					n.AttenuationDB = &att
				}
				if notes.Valid {
					nt := notes.String
					n.Notes = &nt
				}
				nodeMap[n.Identifier] = n
			}
		}
	}

	// Dynamic used_ports calculation for ODP/ODC splitters
	for id, n := range nodeMap {
		if cnt, ok := childCounts[id]; ok {
			n.UsedPorts = cnt
			nodeMap[id] = n
		}
	}

	clientMap := make(map[string]ClientItem)
	var rtoList []RTOAlert
	aktifCount, isolirCount, noLimitCount := 0, 0, 0
	totalRxBps := 0.0
	totalTxBps := 0.0

	for _, c := range clients {
		clientMap[c.IPAddress] = c
		totalRxBps += c.RateRxBps
		totalTxBps += c.RateTxBps

		if c.IsRTO {
			rtoList = append(rtoList, RTOAlert{IP: c.IPAddress, Name: c.NamaKlien, OLT: c.Interface})
		}
		if !c.HasProblem {
			aktifCount++
			if !c.HasQueue {
				noLimitCount++
			}
		}
		if strings.EqualFold(c.StatusList, "ISOLIR") {
			isolirCount++
		}
	}

	var topologyData []NetworkNode
	totalCable := 0.0
	problemCount := 0

	for _, n := range nodeMap {
		n.Status = n.Type
		n.Detail = strings.ToUpper(n.Type)

		if n.Type == "router" && mtErrStr != nil {
			n.Status = "problem"
			n.HasProblem = true
			n.Detail = "PROBLEM: Router API Terputus!"
		} else if n.Type == "olt" {
			if info, ok := ifaceMap[n.Identifier]; ok && !info.Healthy {
				n.Status = "problem"
				n.HasProblem = true
				n.Detail = "PROBLEM: Interface OLT Down"
			} else {
				n.Detail = "OLT / BRIDGE AKTIF"
			}
		} else if n.Type == "switch" {
			n.Status = "switch"
			n.Detail = "SWITCH DISTRIBUSI NOC"
		} else if n.Type == "odc" {
			n.Status = "odc"
			n.Detail = fmt.Sprintf("ODC FDT Cabinet [%d/%d]", n.UsedPorts, n.CapacityPorts)
		} else if n.Type == "odp" {
			n.Status = "odp"
			rxStr := ""
			if n.OpticalPowerRx != nil {
				rxStr = fmt.Sprintf(" | Rx: %.1f dBm", *n.OpticalPowerRx)
			}
			n.Detail = fmt.Sprintf("ODP Splitter [%d/%d Port]%s", n.UsedPorts, n.CapacityPorts, rxStr)
		} else if n.Type == "client" {
			if mt, ok := clientMap[n.Identifier]; ok {
				n.IsFlowing = mt.IsFlowing
				if mt.IsRTO {
					n.Status = "problem"
					n.HasProblem = true
					n.Detail = "RTO / LINK DOWN | " + mt.IPAddress
				} else if strings.EqualFold(mt.StatusList, "ISOLIR") {
					n.Status = "isolir"
					n.HasProblem = true
					n.Detail = "ISOLIR | " + mt.IPAddress
				} else if !mt.HasQueue {
					n.Status = "warning"
					n.Detail = fmt.Sprintf("ONLINE TANPA LIMIT! [%s]", mt.Latency)
				} else {
					n.Status = "aktif"
					n.Detail = fmt.Sprintf("ONLINE (%s) | %s", mt.MaxLimit, mt.Latency)
				}
			}
		}

		if n.HasProblem {
			problemCount++
		}

		if n.ParentIdentifier != nil {
			if pNode, ok := nodeMap[*n.ParentIdentifier]; ok {
				pLat, pLng := pNode.Lat, pNode.Lng
				n.ParentLat = &pLat
				n.ParentLng = &pLng
				dist := math.Round(haversineMeters(pLat, pLng, n.Lat, n.Lng))
				n.DistanceMeters = dist
				totalCable += dist
			}
		}
		topologyData = append(topologyData, n)
	}

	var ifaceNames []string
	for k := range ifaceMap {
		ifaceNames = append(ifaceNames, k)
	}
	sort.Strings(ifaceNames)
	if len(ifaceNames) == 0 {
		ifaceNames = []string{"bridge-olt1", "bridge-olt2"}
	}

	cableStr := fmt.Sprintf("%.0f m", totalCable)
	if totalCable >= 1000 {
		cableStr = fmt.Sprintf("%.2f km", totalCable/1000.0)
	}

	rxMbps := math.Round((totalRxBps/1_000_000.0)*100) / 100
	txMbps := math.Round((totalTxBps/1_000_000.0)*100) / 100

	// Autonomous Telemetry Snapshot Recording (tiap 20 detik)
	if time.Since(lastSnapshotTime) >= 20*time.Second && db != nil {
		lastSnapshotTime = time.Now()
		go recordTelemetrySnapshot(resource, aktifCount, len(rtoList), rxMbps, txMbps)
	}

	return TopologyPayload{
		MikrotikError: mtErrStr,
		Resource:      resource,
		Clients:       clients,
		Interfaces:    ifaceNames,
		TopologyData:  topologyData,
		RTOList:       rtoList,
		Summary: map[string]any{
			"total":       len(clients),
			"aktif":       aktifCount,
			"problem":     problemCount,
			"rto_count":   len(rtoList),
			"isolir":      isolirCount,
			"tanpa_limit": noLimitCount,
			"total_cable": cableStr,
			"rx_mbps":     rxMbps,
			"tx_mbps":     txMbps,
			"latency_ms":  time.Since(start).Milliseconds(),
			"engine":      "Go Sub-Second Daemon (500ms)",
			"sub_second":  true,
		},
	}
}

func recordTelemetrySnapshot(res RouterResource, active, rto int, rxMbps, txMbps float64) {
	if db == nil {
		return
	}
	_, _ = db.Exec(`
		INSERT INTO telemetry_snapshots (cpu_load, memory_usage, active_clients, rto_clients, traffic_rx_mbps, traffic_tx_mbps, created_at)
		VALUES (?, ?, ?, ?, ?, ?, datetime('now', 'localtime'))
	`, res.CPULoad, res.MemoryUsage, active, rto, rxMbps, txMbps)
}

func autoDiscoverSQLite(res RouterResource, ifaceMap map[string]InterfaceStatus, clients []ClientItem, neighbors []map[string]string) {
	var sigBuilder strings.Builder
	sigBuilder.WriteString(mtConfig.Host)
	for _, c := range clients {
		sigBuilder.WriteString("|" + c.IPAddress + ":" + c.NamaKlien + ":" + c.Interface)
	}
	for _, n := range neighbors {
		sigBuilder.WriteString("|" + n["identity"] + ":" + n["interface"])
	}

	sum := md5.Sum([]byte(sigBuilder.String()))
	currentHash := hex.EncodeToString(sum[:])
	if currentHash == lastTopologyHash {
		return
	}

	baseLat, baseLng := -6.1180000, 106.1503000
	routerName := fmt.Sprintf("%s (%s)", firstNonEmpty(res.Identity, "MikroTik Core"), mtConfig.Host)

	// 1. Router Node
	_, _ = db.Exec(`
		INSERT OR IGNORE INTO network_nodes (identifier, name, type, parent_identifier, latitude, longitude)
		VALUES (?, ?, 'router', NULL, ?, ?)
	`, mtConfig.Host, routerName, baseLat, baseLng)

	var rLat, rLng float64
	_ = db.QueryRow(`SELECT latitude, longitude FROM network_nodes WHERE identifier = ?`, mtConfig.Host).Scan(&rLat, &rLng)
	if rLat == 0 && rLng == 0 {
		rLat, rLng = baseLat, baseLng
	}

	// 2. OLT Bridges
	activeIfaces := map[string]bool{}
	for name := range ifaceMap {
		if strings.Contains(strings.ToLower(name), "olt") {
			activeIfaces[name] = true
		}
	}
	for _, c := range clients {
		if c.Interface != "" && strings.Contains(strings.ToLower(c.Interface), "olt") {
			activeIfaces[c.Interface] = true
		}
	}

	var keys []string
	for k := range activeIfaces {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for idx, iface := range keys {
		angle := float64(idx+1) * 1.2
		_, _ = db.Exec(`
			INSERT OR IGNORE INTO network_nodes (identifier, name, type, parent_identifier, latitude, longitude)
			VALUES (?, ?, 'olt', ?, ?, ?)
		`, iface, strings.ToUpper(iface), mtConfig.Host, rLat+(math.Sin(angle)*0.0014), rLng+(math.Cos(angle)*0.0014))
	}

	// 3. Neighbor Switches / Routers (Fase 3)
	for idx, nb := range neighbors {
		nbIdentity := nb["identity"]
		nbIface := nb["interface"]
		if nbIdentity == "" {
			continue
		}
		nbIdentifier := "NEIGHBOR-" + strings.ToUpper(nbIdentity)
		nbType := "switch"
		if strings.Contains(strings.ToLower(nb["platform"]), "router") || strings.Contains(strings.ToLower(nbIdentity), "router") {
			nbType = "router"
		}
		angle := float64(idx+1) * 0.7
		_, _ = db.Exec(`
			INSERT OR IGNORE INTO network_nodes (identifier, name, type, parent_identifier, latitude, longitude)
			VALUES (?, ?, ?, ?, ?, ?)
		`, nbIdentifier, nbIdentity+" ("+nbIface+")", nbType, mtConfig.Host, rLat+(math.Sin(angle)*0.0018), rLng+(math.Cos(angle)*0.0018))
	}

	// 4. Clients - LINDUNGI hierarki ODP/ODC
	for i, c := range clients {
		var existingParent sql.NullString
		err := db.QueryRow(`SELECT parent_identifier FROM network_nodes WHERE identifier = ?`, c.IPAddress).Scan(&existingParent)

		parentID := c.Interface
		if parentID == "" {
			parentID = "bridge-olt1"
		}

		if err == sql.ErrNoRows {
			// Client baru: pasang ke OLT
			var pLat, pLng float64
			pErr := db.QueryRow(`SELECT latitude, longitude FROM network_nodes WHERE identifier = ?`, parentID).Scan(&pLat, &pLng)
			if pErr != nil {
				parentID = mtConfig.Host
				pLat, pLng = rLat, rLng
			}
			angle := float64(i+1) * 0.9
			_, _ = db.Exec(`
				INSERT INTO network_nodes (identifier, name, type, parent_identifier, latitude, longitude)
				VALUES (?, ?, 'client', ?, ?, ?)
			`, c.IPAddress, c.NamaKlien, parentID, pLat+(math.Sin(angle)*0.0011), pLng+(math.Cos(angle)*0.0011))
		} else {
			// Client sudah ada: update nama, JANGAN timpa parent jika sudah di ODP/ODC
			if existingParent.Valid && (strings.HasPrefix(existingParent.String, "ODP") || strings.HasPrefix(existingParent.String, "ODC")) {
				_, _ = db.Exec(`UPDATE network_nodes SET name = ? WHERE identifier = ?`, c.NamaKlien, c.IPAddress)
			} else {
				_, _ = db.Exec(`UPDATE network_nodes SET name = ?, parent_identifier = ? WHERE identifier = ?`, c.NamaKlien, parentID, c.IPAddress)
			}
		}
	}

	lastTopologyHash = currentHash
}

func broadcastSSE(data TopologyPayload) {
	raw, err := json.Marshal(data)
	if err != nil {
		return
	}
	sseMu.Lock()
	defer sseMu.Unlock()
	for ch := range sseClients {
		select {
		case ch <- raw:
		default:
		}
	}
}

func refreshCacheNow() {
	cacheMu.RLock()
	strict := strictModeFlag
	cacheMu.RUnlock()

	data := syncAndBuildPayload(strict)
	cacheMu.Lock()
	latestPayload = data
	cacheMu.Unlock()

	broadcastSSE(data)
}

func startSubSecondWorker() {
	refreshCacheNow()
	// Siklus polling RouterOS sub-second (500 ms) di Goroutine
	go func() {
		for {
			time.Sleep(500 * time.Millisecond)
			refreshCacheNow()
		}
	}()
}

func writeJSON(w http.ResponseWriter, status int, data any) {
	enableCORS(w)
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(data)
}

func main() {
	initDB()
	startSubSecondWorker()

	mux := http.NewServeMux()

	// CORS Preflight Handler
	mux.HandleFunc("OPTIONS /{path...}", func(w http.ResponseWriter, r *http.Request) {
		enableCORS(w)
		w.WriteHeader(http.StatusOK)
	})

	mux.HandleFunc("GET /", func(w http.ResponseWriter, r *http.Request) {
		enableCORS(w)
		http.ServeFile(w, r, "./public/index.html")
	})

	// Real-Time Server-Sent Events (SSE) Push Stream (Sub-second: 500ms)
	mux.HandleFunc("GET /events", func(w http.ResponseWriter, r *http.Request) {
		enableCORS(w)
		w.Header().Set("Content-Type", "text/event-stream")
		w.Header().Set("Cache-Control", "no-cache")
		w.Header().Set("Connection", "keep-alive")

		flusher, ok := w.(http.Flusher)
		if !ok {
			http.Error(w, "SSE tidak didukung", http.StatusInternalServerError)
			return
		}

		ch := make(chan []byte, 4)
		sseMu.Lock()
		sseClients[ch] = true
		sseMu.Unlock()

		defer func() {
			sseMu.Lock()
			delete(sseClients, ch)
			sseMu.Unlock()
		}()

		// Kirim snapshot awal seketika saat browser terhubung
		cacheMu.RLock()
		initialBytes, _ := json.Marshal(latestPayload)
		cacheMu.RUnlock()
		fmt.Fprintf(w, "data: %s\n\n", initialBytes)
		flusher.Flush()

		for {
			select {
			case <-r.Context().Done():
				return
			case msg := <-ch:
				fmt.Fprintf(w, "data: %s\n\n", msg)
				flusher.Flush()
			}
		}
	})

	mux.HandleFunc("GET /telemetry", func(w http.ResponseWriter, r *http.Request) {
		enableCORS(w)
		strict := r.URL.Query().Get("strict") == "1"
		cacheMu.Lock()
		strictModeFlag = strict
		payload := latestPayload
		cacheMu.Unlock()

		writeJSON(w, http.StatusOK, payload)
	})

	mux.HandleFunc("GET /telemetry/history", func(w http.ResponseWriter, r *http.Request) {
		enableCORS(w)
		limit := 30
		rows, err := db.Query(`
			SELECT id, strftime('%H:%M', created_at) as time_str, cpu_load, memory_usage,
			       active_clients, rto_clients, traffic_rx_mbps, traffic_tx_mbps
			FROM telemetry_snapshots
			ORDER BY id ASC
			LIMIT ?
		`, limit)

		var history []HistorySnapshotItem
		if err == nil && rows != nil {
			defer rows.Close()
			for rows.Next() {
				var item HistorySnapshotItem
				var timeStr sql.NullString
				if err := rows.Scan(&item.ID, &timeStr, &item.CPU, &item.RAM, &item.Active, &item.RTO, &item.RxMbps, &item.TxMbps); err == nil {
					if timeStr.Valid {
						item.Time = timeStr.String
					}
					history = append(history, item)
				}
			}
		}

		writeJSON(w, http.StatusOK, map[string]any{
			"status": "success",
			"data":   history,
		})
	})

	mux.HandleFunc("POST /clients/ping", func(w http.ResponseWriter, r *http.Request) {
		enableCORS(w)
		var req struct {
			IPAddress string `json:"ip_address"`
		}
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil || net.ParseIP(req.IPAddress) == nil {
			writeJSON(w, http.StatusBadRequest, map[string]string{"status": "error", "message": "IP tidak valid"})
			return
		}
		c, err := dialShortLived(mtConfig)
		if err != nil {
			writeJSON(w, http.StatusInternalServerError, map[string]string{"status": "error", "message": err.Error()})
			return
		}
		defer c.Close()

		res := runPingOnClient(c, req.IPAddress, 3)
		writeJSON(w, http.StatusOK, map[string]any{"status": "success", "data": res})
	})

	mux.HandleFunc("PATCH /nodes/{id}/coordinates", func(w http.ResponseWriter, r *http.Request) {
		enableCORS(w)
		id := r.PathValue("id")
		var req struct {
			Latitude  float64 `json:"latitude"`
			Longitude float64 `json:"longitude"`
		}
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			writeJSON(w, http.StatusBadRequest, map[string]string{"status": "error"})
			return
		}
		_, _ = db.Exec(`UPDATE network_nodes SET latitude = ?, longitude = ? WHERE id = ?`, req.Latitude, req.Longitude, id)
		writeJSON(w, http.StatusOK, map[string]string{"status": "success"})
	})

	mux.HandleFunc("GET /export-kml", func(w http.ResponseWriter, r *http.Request) {
		enableCORS(w)
		cacheMu.RLock()
		nodes := latestPayload.TopologyData
		cacheMu.RUnlock()

		w.Header().Set("Content-Type", "application/vnd.google-earth.kml+xml")
		w.Header().Set("Content-Disposition", `attachment; filename="topologi-mikrotik-go.kml"`)

		fmt.Fprintln(w, `<?xml version="1.0" encoding="UTF-8"?>`)
		fmt.Fprintln(w, `<kml xmlns="http://www.opengis.net/kml/2.2"><Document><name>Topologi MikroTik FTTH</name>`)
		for _, n := range nodes {
			fmt.Fprintf(w, `<Placemark><name>%s</name><description>%s</description><Point><coordinates>%f,%f,0</coordinates></Point></Placemark>`+"\n",
				n.Name, n.Detail, n.Lng, n.Lat)
		}
		fmt.Fprintln(w, `</Document></kml>`)
	})

	log.Println("================================================================")
	log.Println("🚀 GO SUB-SECOND TELEMETRY ENGINE AKTIF PADA http://127.0.0.1:8080")
	log.Println("   - Real-Time SSE Stream : http://127.0.0.1:8080/events (500ms)")
	log.Println("   - Instant JSON Telemetry: http://127.0.0.1:8080/telemetry")
	log.Println("   - Autonomous Recorder  : Merekam ke SQLite telemetry_snapshots")
	log.Println("================================================================")
	log.Fatal(http.ListenAndServe(":8080", mux))
}
