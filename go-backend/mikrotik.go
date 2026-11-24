package main

import (
	"fmt"
	"net"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/go-routeros/routeros/v3"
)

type RouterConfig struct {
	Host string
	User string
	Pass string
	Port string
}

type RouterResource struct {
	Identity    string `json:"identity"`
	BoardName   string `json:"board_name"`
	Version     string `json:"version"`
	Uptime      string `json:"uptime"`
	CPULoad     int    `json:"cpu_load"`
	MemoryUsage int    `json:"memory_usage"`
}

type InterfaceStatus struct {
	Name     string `json:"name"`
	Type     string `json:"type"`
	Disabled bool   `json:"disabled"`
	Running  bool   `json:"running"`
	Healthy  bool   `json:"healthy"`
}

type ClientItem struct {
	AddressListID string `json:"address_list_id"`
	IPAddress     string `json:"ip_address"`
	StatusList    string `json:"status_list"`
	NamaKlien     string `json:"nama_klien"`
	MacAddress    string `json:"mac_address"`
	Interface     string `json:"interface"`
	Source        string `json:"source"`
	IsPPPActive   bool   `json:"is_ppp_active"`
	HasQueue      bool   `json:"has_queue"`
	MaxLimit      string `json:"max_limit"`
	CurrentRate   string `json:"current_rate"`
	IsFlowing     bool   `json:"is_flowing"`
	IsOnline      bool   `json:"is_online"`
	IsRTO         bool   `json:"is_rto"`
	Latency       string `json:"latency"`
	Loss          string `json:"loss"`
	HasProblem    bool   `json:"has_problem"`
	ProblemMsg    string `json:"problem_msg"`
	RateRxBps     float64 `json:"rate_rx_bps"`
	RateTxBps     float64 `json:"rate_tx_bps"`
	arpDisabled   bool
	queueDisabled bool
}

type PingResult struct {
	IP       string `json:"ip"`
	Online   bool   `json:"online"`
	Sent     int    `json:"sent"`
	Received int    `json:"received"`
	Loss     string `json:"loss"`
	Latency  string `json:"latency"`
}

var (
	apiMu            sync.Mutex
	persistentClient *routeros.Client
	cachedIdentity   string
	cachedOwnIPs     map[string]bool
	lastMetaRefresh  time.Time
)

func getPersistentClient(cfg RouterConfig) (*routeros.Client, error) {
	if persistentClient != nil {
		return persistentClient, nil
	}
	addr := net.JoinHostPort(cfg.Host, cfg.Port)
	c, err := routeros.DialTimeout(addr, cfg.User, cfg.Pass, 3*time.Second)
	if err != nil {
		return nil, err
	}
	persistentClient = c
	return persistentClient, nil
}

func resetPersistentClient() {
	if persistentClient != nil {
		_ = persistentClient.Close()
		persistentClient = nil
	}
}

func dialShortLived(cfg RouterConfig) (*routeros.Client, error) {
	addr := net.JoinHostPort(cfg.Host, cfg.Port)
	return routeros.DialTimeout(addr, cfg.User, cfg.Pass, 3*time.Second)
}

func formatBitPair(raw string, isLimit bool) string {
	if raw == "" || raw == "0/0" {
		if isLimit {
			return "UNLIMITED"
		}
		return "0 bps / 0 bps"
	}
	parts := strings.Split(raw, "/")
	if len(parts) != 2 {
		return raw
	}
	formatUnit := func(s string) string {
		val, _ := strconv.ParseFloat(s, 64)
		if val <= 0 {
			return "0 bps"
		}
		if val >= 1_000_000 {
			return fmt.Sprintf("%.2f Mbps", val/1_000_000)
		}
		if val >= 1_000 {
			return fmt.Sprintf("%.1f Kbps", val/1_000)
		}
		return fmt.Sprintf("%.0f bps", val)
	}
	return formatUnit(parts[0]) + " / " + formatUnit(parts[1])
}

func fetchRouterSnapshot(cfg RouterConfig, strictPhysical bool) (RouterResource, map[string]InterfaceStatus, []ClientItem, []map[string]string, error) {
	apiMu.Lock()
	defer apiMu.Unlock()

	c, err := getPersistentClient(cfg)
	if err != nil {
		return RouterResource{}, nil, nil, nil, err
	}

	// 1. System Resource (pada koneksi hangat hanya ~15-30ms)
	resReply, err := c.Run("/system/resource/print")
	if err != nil {
		resetPersistentClient()
		return RouterResource{}, nil, nil, nil, err
	}

	resData := map[string]string{}
	if len(resReply.Re) > 0 {
		resData = resReply.Re[0].Map
	}

	// Refresh identity & own IPs tiap 30 detik agar hemat bandwidth API
	if cachedIdentity == "" || cachedOwnIPs == nil || time.Since(lastMetaRefresh) > 30*time.Second {
		if idReply, err := c.Run("/system/identity/print"); err == nil && len(idReply.Re) > 0 {
			cachedIdentity = idReply.Re[0].Map["name"]
		}
		own := map[string]bool{cfg.Host: true}
		if addrReply, err := c.Run("/ip/address/print"); err == nil {
			for _, re := range addrReply.Re {
				if addr := re.Map["address"]; addr != "" {
					own[strings.Split(addr, "/")[0]] = true
				}
			}
		}
		cachedOwnIPs = own
		lastMetaRefresh = time.Now()
	}

	identity := firstNonEmpty(cachedIdentity, resData["board-name"], "MikroTik Core")
	totalMem, _ := strconv.ParseFloat(resData["total-memory"], 64)
	freeMem, _ := strconv.ParseFloat(resData["free-memory"], 64)
	memPct := 0
	if totalMem > 0 {
		memPct = int(((totalMem - freeMem) / totalMem) * 100)
	}
	cpuLoad, _ := strconv.Atoi(resData["cpu-load"])

	resource := RouterResource{
		Identity:    identity,
		BoardName:   resData["board-name"],
		Version:     resData["version"],
		Uptime:      resData["uptime"],
		CPULoad:     cpuLoad,
		MemoryUsage: memPct,
	}

	// 2. Interfaces
	ifaceMap := make(map[string]InterfaceStatus)
	if ifaceReply, err := c.Run("/interface/print"); err == nil {
		for _, re := range ifaceReply.Re {
			name := re.Map["name"]
			iType := re.Map["type"]
			lower := strings.ToLower(name)
			if name != "" && (iType == "bridge" || strings.Contains(lower, "olt") || strings.Contains(lower, "ether") || iType == "vlan") {
				disabled := re.Map["disabled"] == "true"
				running := re.Map["running"] == "true"
				ifaceMap[name] = InterfaceStatus{
					Name:     name,
					Type:     iType,
					Disabled: disabled,
					Running:  running,
					Healthy:  !disabled && running,
				}
			}
		}
	} else {
		resetPersistentClient()
		return RouterResource{}, nil, nil, nil, err
	}

	// 3. Ambil 5 sumber data klien secara langsung lewat koneksi persisten
	listMap := map[string]map[string]string{}
	arpMap := map[string]map[string]string{}
	queueMap := map[string]map[string]string{}
	dhcpMap := map[string]map[string]string{}
	pppMap := map[string]map[string]string{}
	allIPs := map[string]bool{}

	if r, err := c.Run("/ip/firewall/address-list/print"); err == nil {
		for _, re := range r.Re {
			ip := re.Map["address"]
			if ip != "" && !strings.Contains(ip, "/") && !cachedOwnIPs[ip] {
				listMap[ip] = re.Map
				allIPs[ip] = true
			}
		}
	}
	if r, err := c.Run("/ppp/active/print"); err == nil {
		for _, re := range r.Re {
			ip := re.Map["address"]
			if ip != "" && !cachedOwnIPs[ip] {
				pppMap[ip] = re.Map
				allIPs[ip] = true
			}
		}
	}
	if r, err := c.Run("/ip/dhcp-server/lease/print"); err == nil {
		for _, re := range r.Re {
			ip := re.Map["address"]
			if ip != "" && !cachedOwnIPs[ip] {
				dhcpMap[ip] = re.Map
				allIPs[ip] = true
			}
		}
	}
	if r, err := c.Run("/queue/simple/print"); err == nil {
		for _, re := range r.Re {
			target := strings.Split(re.Map["target"], "/")[0]
			if net.ParseIP(target) != nil && !cachedOwnIPs[target] {
				queueMap[target] = re.Map
				allIPs[target] = true
			}
		}
	}
	if r, err := c.Run("/ip/arp/print"); err == nil {
		for _, re := range r.Re {
			ip := re.Map["address"]
			if ip == "" || cachedOwnIPs[ip] {
				continue
			}
			arpMap[ip] = re.Map
			isStatic := re.Map["dynamic"] != "true"
			isComplete := re.Map["complete"] == "true"
			ifaceLower := strings.ToLower(re.Map["interface"])
			isLan := strings.Contains(ifaceLower, "olt") || strings.Contains(ifaceLower, "bridge") || strings.Contains(ifaceLower, "lan")
			if isStatic || (isComplete && isLan) {
				allIPs[ip] = true
			}
		}
	}

	var clients []ClientItem
	for ip := range allIPs {
		lInfo := listMap[ip]
		pInfo := pppMap[ip]
		dInfo := dhcpMap[ip]
		aInfo := arpMap[ip]
		qInfo := queueMap[ip]

		var sources []string
		if pInfo != nil {
			sources = append(sources, "PPPoE")
		}
		if dInfo != nil {
			sources = append(sources, "DHCP")
		}
		if aInfo != nil {
			sources = append(sources, "ARP")
		}
		if qInfo != nil {
			sources = append(sources, "Queue")
		}
		if lInfo != nil {
			sources = append(sources, "FW-List")
		}

		rawName := firstNonEmpty(
			lInfo["comment"],
			pInfo["name"],
			dInfo["comment"],
			dInfo["host-name"],
			aInfo["comment"],
			qInfo["name"],
			ip,
		)
		cleanName := strings.TrimSpace(strings.ReplaceAll(strings.ReplaceAll(rawName, "[SIMULASI]", ""), "SIM-", ""))
		if cleanName == "" {
			cleanName = ip
		}

		iface := firstNonEmpty(aInfo["interface"], func() string {
			if pInfo != nil {
				return "PPPoE-Server"
			}
			return "bridge-olt1"
		}())

		rawRate := firstNonEmpty(qInfo["rate"], "0/0")
		rawLimit := qInfo["max-limit"]
		statusList := firstNonEmpty(lInfo["list"], "AUTO_DETECTED")
		rateParts := strings.Split(rawRate, "/")
		var rxBps, txBps float64
		if len(rateParts) == 2 {
			rxBps, _ = strconv.ParseFloat(rateParts[0], 64)
			txBps, _ = strconv.ParseFloat(rateParts[1], 64)
		}

		clients = append(clients, ClientItem{
			AddressListID: lInfo[".id"],
			IPAddress:     ip,
			StatusList:    statusList,
			NamaKlien:     cleanName,
			MacAddress:    firstNonEmpty(aInfo["mac-address"], dInfo["mac-address"], pInfo["caller-id"], "-"),
			Interface:     iface,
			Source:        strings.Join(sources, "+"),
			IsPPPActive:   pInfo != nil,
			HasQueue:      qInfo != nil && rawLimit != "" && rawLimit != "0/0",
			MaxLimit:      formatBitPair(rawLimit, true),
			CurrentRate:   formatBitPair(rawRate, false),
			IsFlowing:     rawRate != "0/0",
			RateRxBps:     rxBps,
			RateTxBps:     txBps,
			arpDisabled:   aInfo != nil && (aInfo["disabled"] == "true" || aInfo["invalid"] == "true"),
			queueDisabled: qInfo != nil && qInfo["disabled"] == "true",
		})
	}

	// 4. Evaluasi kesehatan setiap klien
	for i := range clients {
		cl := &clients[i]
		linkDisabled := cl.arpDisabled || cl.queueDisabled
		ifaceInfo, hasIface := ifaceMap[cl.Interface]
		ifaceDown := hasIface && !ifaceInfo.Healthy
		isIsolir := strings.EqualFold(cl.StatusList, "ISOLIR")

		if linkDisabled {
			cl.IsOnline = false
			cl.Latency = "RTO (Disabled)"
			cl.Loss = "100%"
		} else if cl.IsFlowing || cl.IsPPPActive {
			cl.IsOnline = true
			cl.Latency = "Live Traffic"
			cl.Loss = "0%"
		} else if !strictPhysical {
			cl.IsOnline = true
			cl.Latency = "Online (Auto)"
			cl.Loss = "0%"
		} else {
			pingRes := runPingOnClient(c, cl.IPAddress, 1)
			cl.IsOnline = pingRes.Online
			cl.Latency = pingRes.Latency
			cl.Loss = pingRes.Loss
		}

		cl.IsRTO = !cl.IsOnline && !ifaceDown && !isIsolir
		cl.HasProblem = ifaceDown || cl.IsRTO || isIsolir
		if ifaceDown {
			cl.ProblemMsg = "PORT " + cl.Interface + " DOWN"
		} else if cl.IsRTO {
			cl.ProblemMsg = "RTO / LINK DOWN"
		} else if isIsolir {
			cl.ProblemMsg = "STATUS ISOLIR"
		} else {
			cl.ProblemMsg = "ONLINE"
		}
	}

	var neighbors []map[string]string
	if nReply, err := c.Run("/ip/neighbor/print"); err == nil {
		for _, re := range nReply.Re {
			neighbors = append(neighbors, re.Map)
		}
	}

	return resource, ifaceMap, clients, neighbors, nil
}

func runPingOnClient(c *routeros.Client, ip string, count int) PingResult {
	reply, err := c.Run("/tool/ping", "=address="+ip, "=count="+strconv.Itoa(count), "=interval=100ms")
	if err != nil {
		return PingResult{IP: ip, Online: false, Sent: count, Received: 0, Loss: "100%", Latency: "RTO"}
	}
	received := 0
	lastTime := ""
	for _, re := range reply.Re {
		if t, ok := re.Map["time"]; ok && re.Map["status"] == "" {
			received++
			lastTime = t
		}
	}
	if received > count {
		received = count
	}
	loss := int(float64(count-received) / float64(count) * 100)
	lat := "RTO"
	if received > 0 {
		if lastTime != "" {
			lat = lastTime
		} else {
			lat = "1ms"
		}
	}
	return PingResult{
		IP:       ip,
		Online:   received > 0,
		Sent:     count,
		Received: received,
		Loss:     fmt.Sprintf("%d%%", loss),
		Latency:  lat,
	}
}

func registerClientOnRouter(cfg RouterConfig, nama, ip, mac, iface, maxLimit string) error {
	c, err := dialShortLived(cfg)
	if err != nil {
		return err
	}
	defer c.Close()

	_, _ = c.Run("/ip/arp/add", "=address="+ip, "=mac-address="+mac, "=interface="+iface, "=comment="+nama)
	_, _ = c.Run("/ip/firewall/address-list/add", "=list=PELANGGAN_AKTIF", "=address="+ip, "=comment="+nama)

	if strings.TrimSpace(maxLimit) != "" {
		cleanName := regexp.MustCompile(`[^A-Za-z0-9_-]`).ReplaceAllString(nama, "_")
		_, _ = c.Run("/queue/simple/add", "=name=SIM-"+cleanName, "=target="+ip+"/32", "=max-limit="+maxLimit, "=comment=Paket "+maxLimit)
	}
	return nil
}

func deleteClientOnRouter(cfg RouterConfig, ip string) error {
	c, err := dialShortLived(cfg)
	if err != nil {
		return err
	}
	defer c.Close()

	if lists, err := c.Run("/ip/firewall/address-list/print"); err == nil {
		for _, re := range lists.Re {
			if re.Map["address"] == ip {
				_, _ = c.Run("/ip/firewall/address-list/remove", "=.id="+re.Map[".id"])
			}
		}
	}
	if arps, err := c.Run("/ip/arp/print"); err == nil {
		for _, re := range arps.Re {
			if re.Map["address"] == ip && re.Map["dynamic"] != "true" {
				_, _ = c.Run("/ip/arp/remove", "=.id="+re.Map[".id"])
			}
		}
	}
	if queues, err := c.Run("/queue/simple/print"); err == nil {
		for _, re := range queues.Re {
			if strings.Split(re.Map["target"], "/")[0] == ip {
				_, _ = c.Run("/queue/simple/remove", "=.id="+re.Map[".id"])
			}
		}
	}
	return nil
}

func firstNonEmpty(vals ...string) string {
	for _, v := range vals {
		if strings.TrimSpace(v) != "" {
			return v
		}
	}
	return ""
}