


<div style="font-family: Helvetica,Arial,sans-serif;min-width:1000px;overflow:auto;line-height:2">
  <div style="margin:50px auto;width:70%;padding:20px 0">
    <div style="border-bottom:1px solid #eee">
      <a href="" ><img src="https://codesandbox.com.ng/market_for_opticals/public/pictures/m4o_logo.png" alt="market for opticals logo"></a>
    </div>
    @if($transaction_details['type'] == "delivery")
    <p style="font-size:1.1em">Hello, {{$admins_info["name"]}}</p>
    <p>Your order is {{$transaction_details['status']}}</p>

   @else
   <p style="font-size:1.1em">Hello, {{$admins_info["name"]}}</p>
    <p>You have a new order kindly login to your dashboard to view the order</p>
   @endif
    <hr style="border:none;border-top:1px solid #eee" />
   
  </div>
</div>