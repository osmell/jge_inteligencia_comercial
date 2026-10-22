const http=require('http'),fs=require('fs'),path=require('path')
const root=process.argv[2], port=+process.argv[3], api=process.argv[4]
const csp=`default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: ${api}; connect-src 'self' ${api} ws://localhost:8080 wss://localhost:8080; font-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'`
const types={'.html':'text/html','.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml','.png':'image/png','.woff2':'font/woff2','.woff':'font/woff','.ico':'image/x-icon','.json':'application/json'}
http.createServer((req,res)=>{
  let p=path.join(root,decodeURIComponent(req.url.split('?')[0])); if(!p.startsWith(root)||!fs.existsSync(p)||fs.statSync(p).isDirectory()) p=path.join(root,'index.html')
  res.writeHead(200,{'Content-Type':types[path.extname(p)]||'application/octet-stream','Content-Security-Policy':csp,'X-Content-Type-Options':'nosniff','X-Frame-Options':'DENY','Referrer-Policy':'no-referrer'})
  fs.createReadStream(p).pipe(res)
}).listen(port,'0.0.0.0',()=>console.log('csp server',port))
