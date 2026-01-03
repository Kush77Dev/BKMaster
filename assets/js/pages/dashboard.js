/**
* Theme: Larkon - Responsive Bootstrap 5 Admin Dashboard
* Author: Techzaa
* Module/App: Dashboard
*/

//
// Conversions (Radial Chart)
// 
var options = {
    chart: {
        height: 292,
        type: 'radialBar',
    },
    plotOptions: {
        radialBar: {
            startAngle: -135,
            endAngle: 135,
            dataLabels: {
                name: {
                    fontSize: '14px',
                    color: "undefined",
                    offsetY: 100
                },
                value: {
                    offsetY: 55,
                    fontSize: '20px',
                    color: undefined,
                    formatter: function (val) {
                        return val + "%";
                    }
                }
            },
            track: {
                background: "rgba(170,184,197, 0.2)",
                margin: 0
            },
        }
    },
    fill: {
        gradient: {
            enabled: true,
            shade: 'dark',
            shadeIntensity: 0.2,
            inverseColors: false,
            opacityFrom: 1,
            opacityTo: 1,
            stops: [0, 50, 65, 91]
        },
    },
    stroke: {
        dashArray: 4
    },

    colors: ["#4CAF7C"], 

    series: [window.dynamicChartData ? window.dynamicChartData.conversions.rate : 0],
    labels: ['Retention Rate'],
    responsive: [{
        breakpoint: 380,
        options: {
            chart: {
                height: 180
            }
        }
    }],
    grid: {
        padding: {
            top: 0,
            right: 0,
            bottom: 0,
            left: 0
        }
    }
}

var chart = new ApexCharts(
    document.querySelector("#conversions"),
    options
);
chart.render();


//
// Performance Chart
//
var options = {
    series: [
        {
            name: "Orders",
            type: "bar",
            data: window.dynamicChartData ? window.dynamicChartData.performance.orders : [],
        },
        {
            name: "Revenue",
            type: "area",
            data: window.dynamicChartData ? window.dynamicChartData.performance.revenue : [],
        },
    ],
    chart: {
        height: 313,
        type: "line",
        toolbar: {
            show: false,
        },
    },
    stroke: {
        dashArray: [0, 0],
        width: [0, 2],
        curve: 'smooth'
    },
    fill: {
        opacity: [1, 1],
        type: ['solid', 'gradient'],
        gradient: {
            type: "vertical",
            inverseColors: false,
            opacityFrom: 0.5,
            opacityTo: 0,
            stops: [0, 90]
        },
    },
    markers: {
        size: [0, 0],
        strokeWidth: 2,
        hover: {
            size: 4,
        },
    },
    xaxis: {
        categories: ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"],
        axisTicks: { show: false },
        axisBorder: { show: false },
    },
    yaxis: [
        {
            seriesName: 'Orders',
            axisTicks: {
                show: true,
            },
            axisBorder: {
                show: true,
                color: '#4CAF7C'
            },
            labels: {
                style: {
                    colors: '#4CAF7C',
                }
            },
            title: {
                text: "Orders",
                style: {
                    color: '#4CAF7C',
                }
            },
            tooltip: {
                enabled: true
            }
        },
        {
            seriesName: 'Revenue',
            opposite: true,
            axisTicks: {
                show: true,
            },
            axisBorder: {
                show: true,
                color: '#3C9267'
            },
            labels: {
                style: {
                    colors: '#3C9267',
                }
            },
            title: {
                text: "Revenue",
                style: {
                    color: '#3C9267',
                }
            },
        },
    ],
    grid: {
        show: true,
        strokeDashArray: 3,
        yaxis: { lines: { show: true } },
        padding: { top: 0, right: 15, bottom: 0, left: 10 },
    },
    legend: {
        show: true,
        horizontalAlign: "center",
        offsetY: 5,
        markers: { width: 9, height: 9, radius: 6 },
        itemMargin: { horizontal: 10, vertical: 0 },
    },
    plotOptions: {
        bar: {
            columnWidth: "30%",
            barHeight: "70%",
            borderRadius: 3,
            margin: 10,
        },
    },

    // CHANGE HERE ↓↓↓↓↓
    colors: ["#4CAF7C", "#3C9267"],  
    // Mint Green (bar) + darker Mint (area)
    // CHANGE HERE ↑↑↑↑↑

    tooltip: {
        shared: true,
        intersect: false,
        custom: function({ series, seriesIndex, dataPointIndex, w }) {
            const month = w.globals.categoryLabels[dataPointIndex];
            const orders = series[0][dataPointIndex];
            const revenue = series[1][dataPointIndex];
            
            // Format revenue with thousand separators
            const formatRevenue = (val) => {
                return '₹' + val.toLocaleString('en-IN', { 
                    minimumFractionDigits: 0, 
                    maximumFractionDigits: 0 
                });
            };
            
            return `
                <div style="
                    background: #ffffff;
                    border-radius: 8px;
                    padding: 12px 16px;
                    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                    min-width: 180px;
                    border: 1px solid #e5e7eb;
                ">
                    <div style="
                        font-size: 13px;
                        font-weight: 600;
                        color: #1a1a1a;
                        margin-bottom: 10px;
                        padding-bottom: 8px;
                        border-bottom: 1px solid #f0f0f0;
                    ">${month}</div>
                    
                    <div style="margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between;">
                        <div style="display: flex; align-items: center;">
                            <span style="
                                display: inline-block;
                                width: 8px;
                                height: 8px;
                                border-radius: 50%;
                                background: #4CAF7C;
                                margin-right: 8px;
                            "></span>
                            <span style="font-size: 12px; color: #666;">Orders</span>
                        </div>
                        <span style="
                            font-size: 14px;
                            font-weight: 700;
                            color: #1a1a1a;
                            margin-left: 12px;
                        ">${orders}</span>
                    </div>
                    
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <div style="display: flex; align-items: center;">
                            <span style="
                                display: inline-block;
                                width: 8px;
                                height: 8px;
                                border-radius: 50%;
                                background: #3C9267;
                                margin-right: 8px;
                            "></span>
                            <span style="font-size: 12px; color: #666;">Revenue</span>
                        </div>
                        <span style="
                            font-size: 14px;
                            font-weight: 700;
                            color: #1a1a1a;
                            margin-left: 12px;
                        ">${formatRevenue(revenue)}</span>
                    </div>
                </div>
            `;
        }
    },
}

var chart = new ApexCharts(
    document.querySelector("#dash-performance-chart"),
    options
);
chart.render();

// Handle Filter Clicks
document.querySelectorAll('.chart-filter').forEach(button => {
    button.addEventListener('click', function() {
        // Remove active class from all
        document.querySelectorAll('.chart-filter').forEach(btn => btn.classList.remove('active'));
        // Add active class to clicked
        this.classList.add('active');

        var filter = this.getAttribute('data-filter');
        var newData = window.dynamicChartData.performance;
        
        var ordersData = [];
        var revenueData = [];
        var categories = [];

        if (filter === '1M') {
            ordersData = newData.filter1M.orders;
            revenueData = newData.filter1M.revenue;
            categories = newData.filter1M.labels;
        } else if (filter === '6M') {
            ordersData = newData.filter6M.orders;
            revenueData = newData.filter6M.revenue;
            categories = newData.filter6M.labels;
        } else if (filter === 'ALL') {
            ordersData = newData.filterALL.orders;
            revenueData = newData.filterALL.revenue;
            categories = newData.filterALL.labels;
        } else {
            // Default 1Y
            ordersData = newData.orders;
            revenueData = newData.revenue;
            categories = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
        }

        chart.updateOptions({
            xaxis: {
                categories: categories
            },
            series: [
                { data: ordersData },
                { data: revenueData }
            ]
        });
    });
});


class VectorMap {
    initWorldMapMarker() {
        if (document.getElementById('world-map-markers')) {
            const map = new jsVectorMap({
                map: 'world',
                selector: '#world-map-markers',
                zoomOnScroll: true,
                zoomButtons: false,
                markersSelectable: true,
                markers: [
                    { name: "Canada", coords: [56.1304, -106.3468] },
                    { name: "Brazil", coords: [-14.2350, -51.9253] },
                    { name: "Russia", coords: [61, 105] },
                    { name: "China", coords: [35.8617, 104.1954] },
                    { name: "United States", coords: [37.0902, -95.7129] }
                ],
                markerStyle: {
                    initial: { fill: "#7f56da" },
                    selected: { fill: "#22c55e" }
                },
                labels: {
                    markers: {
                        render: marker => marker.name
                    }
                },
                regionStyle: {
                    initial: {
                        fill: 'rgba(0, 124, 248, 0.3)',
                    },
                },
            });
        }
    }

    initIndiaMapMarker() {
        if (document.getElementById('india-map-markers')) {
            const stateData = window.dynamicChartData.stateOrders || {};
            
            // Calculate the maximum order count from the data
            const maxOrders = Math.max(...Object.values(stateData), 1);
            
            const map = new jsVectorMap({
                map: 'in_mill',
                selector: '#india-map-markers',
                zoomOnScroll: false,
                zoomButtons: false,
                regionStyle: {
                    initial: {
                        fill: '#f0f4f2', // Very light green-gray
                        stroke: "#fff",
                        strokeWidth: 1.5,
                        fillOpacity: 1
                    },
                    hover: {
                        fill: '#4CAF7C', // Brand Color
                        fillOpacity: 0.9,
                        cursor: 'pointer'
                    },
                    selected: {
                        fill: '#2E7D32' // Dark green for selected
                    }
                },
                series: {
                    regions: [{
                        attribute: 'fill',
                        scale: ['#C8E6C9', '#2E7D32'], // Light green to Dark Green
                        values: stateData,
                        min: 0,
                        max: maxOrders, // Dynamic max based on actual data
                        legend: false // Disable built-in legend, we'll create custom one
                    }]
                },
                showTooltip: true,
                onRegionTooltipShow(event, tooltip, code) {
                    const count = stateData[code] || 0;
                    tooltip.text(
                        `<div style="padding: 8px; border-left: 3px solid #4CAF7C;">` +
                        `<h6 style="margin-bottom: 4px; font-weight: 600; color: #1a1a1a;">${tooltip.text()}</h6>` +
                        `<p style="margin-bottom: 0; font-size: 13px; color: #666;">Total Orders: <span style="color: #4CAF7C; font-weight: 700;">${count}</span></p>` +
                        `</div>`,
                        true
                    );
                },
                onRegionClick(event, code) {
                    // Access local data
                    const allStateOrders = window.dynamicChartData.detailedStateOrders || {};
                    const stateOrders = allStateOrders[code] || [];
                    
                    const modal = new bootstrap.Modal(document.getElementById('stateOrdersModal'));
                    const modalNameSpan = document.getElementById('modalStateName');
                    const tableBody = document.getElementById('stateOrdersTableBody');
                    const loadingDiv = document.getElementById('stateOrdersLoading');
                    const emptyDiv = document.getElementById('stateOrdersEmpty');
                    
                    // Clear previous data
                    tableBody.innerHTML = '';
                    modalNameSpan.textContent = code; 
                    loadingDiv.style.display = 'none';
                    
                    if (stateOrders.length > 0) {
                        emptyDiv.style.display = 'none';
                        stateOrders.forEach(order => {
                            // Helper for badges (simple mappings)
                            const paymentBadgeClass = {
                                'paid': 'bg-success text-light',
                                'unpaid': 'bg-light text-dark',
                                'partial': 'bg-warning text-dark',
                                'refunded': 'bg-info text-light',
                                'failed': 'bg-danger text-light'
                            }[order.payment_status] || 'bg-light text-dark';
                            
                            const statusBadgeClass = {
                                'pending': 'border border-secondary text-secondary',
                                'processing': 'border border-warning text-warning',
                                'shipped': 'border border-info text-info',
                                'delivered': 'border border-success text-success',
                                'cancelled': 'border border-danger text-danger',
                                'draft': 'border border-secondary text-secondary',
                                'packaging': 'border border-warning text-warning'
                            }[order.status] || 'border border-secondary text-secondary';
                            
                            const row = `
                                <tr>
                                    <td><a href="order/detail-order.php?id=${order.id}" class="link-primary fw-medium">#${order.order_number || ('ORD-' + order.id.toString().padStart(6, '0'))}</a></td>
                                    <td>${order.formatted_date}</td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="avatar-sm flex-shrink-0">
                                                <span class="avatar-title rounded-circle fs-14 text-white" style="background-color: ${order.avatar_color}; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;">
                                                    ${order.initials}
                                                </span>
                                            </div>
                                            <a href="customers/detail-customer.php?id=${order.user_id || '#'}" class="link-primary fw-medium">${order.customer_name}</a>
                                        </div>
                                    </td>
                                    <td>${order.formatted_amount}</td>
                                    <td>
                                        <span class="badge ${paymentBadgeClass} px-2 py-1 fs-13">
                                            ${order.payment_status.charAt(0).toUpperCase() + order.payment_status.slice(1)}
                                        </span>
                                    </td>
                                    <td>${order.item_count}</td>
                                    <td>
                                        <span class="badge ${statusBadgeClass} px-2 py-1 fs-13">
                                            ${order.status.charAt(0).toUpperCase() + order.status.slice(1)}
                                        </span>
                                    </td>
                                    <td>
                                        <a href="order/detail-order.php?id=${order.id}" class="btn btn-light btn-sm">
                                            <iconify-icon icon="solar:eye-broken" class="align-middle fs-18"></iconify-icon>
                                        </a>
                                    </td>
                                </tr>
                            `;
                            tableBody.insertAdjacentHTML('beforeend', row);
                        });
                    } else {
                        emptyDiv.style.display = 'block';
                    }
                    
                    modal.show();
                },
                onLoaded(map) {
                    // Calculate quartiles for dynamic color assignment
                    const q1 = Math.round(maxOrders * 0.25);
                    const q2 = Math.round(maxOrders * 0.5);
                    const q3 = Math.round(maxOrders * 0.75);
                    
                    // Manually set colors for states with orders to ensure proper color distribution
                    Object.keys(stateData).forEach(stateCode => {
                        const orderCount = stateData[stateCode];
                        if (orderCount > 0) {
                            // Calculate color based on order count using dynamic quartiles
                            let color;
                            if (orderCount > q3) {
                                color = '#2E7D32'; // Dark green for top quartile (q3+)
                            } else if (orderCount > q2) {
                                color = '#4CAF7C'; // Medium green for 3rd quartile (q2-q3)
                            } else if (orderCount > q1) {
                                color = '#81C784'; // Medium-light green for 2nd quartile (q1-q2)
                            } else {
                                color = '#C8E6C9'; // Light green for 1st quartile (1-q1)
                            }
                            
                            // Apply the color to the region using setAttribute
                            const region = map.regions[stateCode];
                            if (region && region.element && region.element.shape && region.element.shape.node) {
                                region.element.shape.node.setAttribute('fill', color);
                                region.element.shape.node.style.fill = color;
                            }
                        }
                    });
                    
                    // Create custom legend
                    const mapContainer = document.getElementById('india-map-markers');
                    if (mapContainer && !document.getElementById('custom-map-legend')) {
                        // Calculate quartiles
                        const q1 = Math.round(maxOrders * 0.25);
                        const q2 = Math.round(maxOrders * 0.5);
                        const q3 = Math.round(maxOrders * 0.75);
                        
                        const legendHTML = `
                            <div id="custom-map-legend" style="
                                position: absolute;
                                top: 10px;
                                right: 10px;
                                background: white;
                                padding: 12px;
                                border-radius: 6px;
                                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                                font-size: 12px;
                                z-index: 10;
                            ">
                                <div style="font-weight: 600; margin-bottom: 8px; color: #1a1a1a;">Orders</div>
                                <div style="display: flex; align-items: center; margin-bottom: 4px;">
                                    <span style="display: inline-block; width: 20px; height: 12px; background: #C8E6C9; margin-right: 6px; border-radius: 2px;"></span>
                                    <span>1-${q1}</span>
                                </div>
                                <div style="display: flex; align-items: center; margin-bottom: 4px;">
                                    <span style="display: inline-block; width: 20px; height: 12px; background: #81C784; margin-right: 6px; border-radius: 2px;"></span>
                                    <span>${q1 + 1}-${q2}</span>
                                </div>
                                <div style="display: flex; align-items: center; margin-bottom: 4px;">
                                    <span style="display: inline-block; width: 20px; height: 12px; background: #4CAF7C; margin-right: 6px; border-radius: 2px;"></span>
                                    <span>${q2 + 1}-${q3}</span>
                                </div>
                                <div style="display: flex; align-items: center; margin-bottom: 4px;">
                                    <span style="display: inline-block; width: 20px; height: 12px; background: #2E7D32; margin-right: 6px; border-radius: 2px;"></span>
                                    <span>${q3 + 1}+</span>
                                </div>
                            </div>
                        `;
                        
                        mapContainer.style.position = 'relative';
                        mapContainer.insertAdjacentHTML('beforeend', legendHTML);
                    }
                }
            });
        }
    }

    init() {
        this.initWorldMapMarker();
        this.initIndiaMapMarker();
    }
}

document.addEventListener('DOMContentLoaded', function () {
    new VectorMap().init();
});
